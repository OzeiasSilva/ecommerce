<?php

namespace Hcode\Model;

use \Hcode\DB\Sql;
use \Hcode\Model;
use \Hcode\Mailer;
use \Hcode\Model\Product;
use \Hcode\Model\User;

class Cart extends Model {

	const SESSION = "Cart";
	const SESSION_ERROR = "CartError";

	public static function getFromSession(){

		$cart = new Cart();

		if(isset($_SESSION[Cart::SESSION]) && (int)$_SESSION[Cart::SESSION]['idcart'] > 0){
			//Se o carrinho existir, executa a função abaixo
			$cart->get((int)$_SESSION[Cart::SESSION]['idcart']);

		} else { 
			//Se o carrinho não existir ainda, executa as funções abaixo
			$cart->getFromSessionID();

			if (!(int)$cart->getidcart() > 0){
				//Criar carrinho novo
				$data = array(
					"dessessionid"=>session_id()
				);

				if (User::checkLogin(false)){

					//Verificar se tem usuário logado
					$user = User::getFromSession();
					$data["iduser"] = $user->getiduser();

				}

				$cart->setData($data);

				$cart->save();

				$cart->setToSession();

			}

		}

		return $cart;

	}

	// Função para adicionar o carrinho a sessão
	public function setToSession(){

		$_SESSION[Cart::SESSION] = $this->getValues();

	}

	//Função para recuperar o carrinho
	public function getFromSessionID(){

		$sql = new Sql();

		//var_dump("SELECT * FROM tb_carts WHERE dessessionid = ".session_id()."");
		//exit;

		$results = $sql->select("SELECT * FROM tb_carts WHERE dessessionid = :dessessionid", array(
			":dessessionid"=>session_id()
		));

		//var_dump($results);
		//exit;
		if (count($results) > 0){

			$this->setData($results[0]);

		}

	}

	public function get(int $idcart){

		$sql = new Sql();

		$results = $sql->select("SELECT * FROM tb_carts WHERE idcart = :idcart", array(
			":idcart"=>$idcart
		));

		if (count($results[0]) > 0){

			$this->setData($results[0]);

		}

	}

	//Função para salvar o carrinho no banco
	public function save(){

		$sql = new Sql();

		$results =  $sql->select("CALL sp_carts_save(:idcart, :dessessionid, :iduser, :deszipcode, :vlfreight, :nrdays)", array(
			":idcart"=>$this->getidcart(),
			":dessessionid"=>$this->getdessessionid(),
			":iduser"=>$this->getiduser(),
			":deszipcode"=>$this->getdeszipcode(),
			":vlfreight"=>$this->getvlfreight(),
			":nrdays"=>$this->getnrdays()
		));

		$this->setData($results[0]);

	}

	public function addProduct(Product $product){

		$sql = new Sql();

		$sql->query("INSERT INTO tb_cartsproducts (idcart, idproduct) VALUES (:idcart, :idproduct)", array(
			':idcart'=>$this->getidcart(),
			':idproduct'=>$product->getidproduct()
		));

		$this->getCalculateTotal();

	}

	public function removeProduct(Product $product, $all = false){

		$sql = new Sql();

		if($all){

			$sql->query("UPDATE tb_cartsproducts SET dtremoved = NOW() WHERE idcart = :idcart AND idproduct = :idproduct AND dtremoved IS NULL", array(
				':idcart'=>$this->getidcart(),
				':idproduct'=>$product->getidproduct()
			));

		} else {

			$sql->query("UPDATE tb_cartsproducts SET dtremoved = NOW() WHERE idcart = :idcart AND idproduct = :idproduct AND dtremoved IS NULL LIMIT 1", array(
				':idcart'=>$this->getidcart(),
				':idproduct'=>$product->getidproduct()
			));

		}

		$this->getCalculateTotal();

	}

	public function getProducts(){

		$sql = new Sql();

		$rows = $sql->select("
			SELECT b.idproduct, b.desproduct, b.vlprice, b.vlwidth, b.vlheight, b.vllength, b.vlweight, b.desurl, COUNT(*) AS nrqtd, SUM(b.vlprice) AS vltotal 
			FROM tb_cartsproducts a 
			INNER JOIN tb_products b ON a.idproduct = b.idproduct 
			WHERE a.idcart = :idcart AND a.dtremoved IS NULL 
			GROUP BY b.idproduct, b.desproduct, b.vlprice, b.vlwidth, b.vlheight, b.vllength, b.vlweight, b.desurl
			ORDER BY b.desproduct
			", array(
				':idcart'=>$this->getidcart()
			));

		return Product::checkList($rows);

	}


	public function getProductsTotals(){

		$sql = new Sql();

		$results = $sql->select("
			SELECT SUM(vlprice) AS vlprice, SUM(vlwidth) AS vlwidth, SUM(vlheight) AS vlheight, SUM(vllength) AS vllength, SUM(vlweight) AS vlweight, COUNT(*) AS nrqtd
			FROM tb_products a
			INNER JOIN tb_cartsproducts b ON a.idproduct = b.idproduct
			WHERE b.idcart = :idcart AND dtremoved IS NULL
		", array(
			':idcart'=>$this->getidcart()
		));

		if (count($results) > 0 ) {

			return $results[0];

		} else {

			return [];

		}

	}

	public function setFreight($nrzipcode){

		$nrzipcode = str_replace('-', '', $nrzipcode);

		$totals = $this->getProductsTotals();

		if ($totals['nrqtd'] > 0) {

			if ($totals['vlheight'] < 2) $totals['vlheight'] = 2;
			if ($totals['vllength'] < 16) $totals['vllength'] = 16;

			// Se o valor for menor que R$ 3.000,00 será enviado via PAC, 
			//caso contrário via SEDEX
			if ($totals['vlprice'] < 3000){

				$codPostal = '04510'; //cód. do PAC

			} else {

				$codPostal = '40010'; //cód. do SEDEX

			}

			$qs = http_build_query([
				'nCdEmpresa'=>'',
				'sDsSenha'=>'',
				'nCdServico'=>$codPostal,
				'sCepOrigem'=>'06529210', // CEP de Cajamar - SP
				'sCepDestino'=>$nrzipcode,
				'nVlPeso'=>$totals['vlweight'],
				'nCdFormato'=>'1',
				'nVlComprimento'=>$totals['vllength'],
				'nVlAltura'=>$totals['vlheight'],
				'nVlLargura'=>$totals['vlwidth'],
				'nVlDiametro'=>'0',
				'sCdMaoPropria'=>'N',
				'nVlValorDeclarado'=>$totals['vlprice'],
				'sCdAvisoRecebimento'=>'S'
			]);

			/*
			Código do serviço:
				Código Serviço
				40010 SEDEX Varejo
				40045 SEDEX a Cobrar Varejo
				40215 SEDEX 10 Varejo
				40290 SEDEX Hoje Varejo
				41106 PAC Varejo
				04510 PAC atualizado

			Formato da encomenda (incluindo embalagem).
			Valores possíveis: 1, 2 ou 3
				1 – Formato caixa/pacote
				2 – Formato rolo/prisma
				3 - Envelope
			*/
			
			$xml = simplexml_load_file("http://ws.correios.com.br/calculador/CalcPrecoPrazo.asmx/CalcPrecoPrazo?".$qs);

			$result = $xml->Servicos->cServico;

			if ($result->MsgErro != ''){

				Cart::setMsgError($result->MsgErro);

			} else {

				Cart::clearMsgError();

			}

			$this->setnrdays($result->PrazoEntrega);
			$this->setvlfreight(Cart::formatValueToDecimal($result->Valor));
			$this->setdeszipcode($nrzipcode);

			$this->save();

			return $result;

		} else {


		}

	}

	public static function formatValueToDecimal($value):float{

		$value = str_replace('.', '', $value);
		return str_replace (',', '.', $value);

	}

	public static function setMsgError($msg){

		$_SESSION[Cart::SESSION_ERROR] = $msg;

	}

	public static function getMsgError(){

		$msg = (isset($_SESSION[Cart::SESSION_ERROR])) ? $_SESSION[Cart::SESSION_ERROR] : "";

		Cart::clearMsgError();

		return $msg;


	}

	public static function clearMsgError(){

		$_SESSION[Cart::SESSION_ERROR] = NULL;

	}

	public function updateFreight(){

		if ($this->getdeszipcode() != '') {

			$this->setFreight($this->getdeszipcode());

		}

	}

	public function getValues(){

		$this->getCalculateTotal();

		return parent::getValues();

	}


	public function getCalculateTotal(){

		$this->updateFreight();

		$totals = $this->getProductsTotals();

		$this->setvlsubtotal($totals['vlprice']);
		$this->setvltotal($totals['vlprice'] + $this->getvlfreight());


	}

}

?>