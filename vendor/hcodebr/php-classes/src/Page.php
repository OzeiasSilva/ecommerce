<?php 

namespace Hcode;

use Rain\Tpl;
use \Hcode\Model\User;

class Page {

	private $tpl;
	private $options = [];
	private $defaults = [
		"header"=>true,
		"footer"=>true,
		"data"=>[]
	];

	public function __construct($opts = array(), $tpl_dir = "/views/"){

		$this->options = array_merge($this->defaults, $opts);

		$config = array(
			"tpl_dir"	=> $_SERVER['DOCUMENT_ROOT'].$tpl_dir,
			"cache"		=> $_SERVER['DOCUMENT_ROOT']."/views-cache/",
			"debug"		=> false
		);

		Tpl::configure($config);

		$this->tpl = new Tpl;

		if (isset($_SESSION[User::SESSION])) {

			$this->tpl->assign("user", $_SESSION[User::SESSION]);

		}

		$this->setData($this->options["data"]);

		if ($this->options['header'] === true) $this->tpl->draw("header");

	}

	private function setData($data = array()){

		foreach ($data as $key => $value) {
			$this->tpl->assign($key, $value);
		}

	}

	public function setTpl($name, $data = array(), $returnHTML = false){

		$this->setData($data);

		return $this->tpl->draw($name, $returnHTML);

	}

	public function __destruct(){

		if ($this->options['footer'] === true) $this->tpl->draw("footer");

	}

}

?>