<?php

namespace Yauhenko\RestBundle\Model;

abstract class AbstractModel {

	public function __construct(array $data = []) {
		foreach($data as $key => $value) {
			$method = "set$key";
			if(method_exists($this, $method)) {
				$this->$method($value);
			} elseif(property_exists($this, $key)) {
				$this->$key = $value;
			}
		}
	}

}
