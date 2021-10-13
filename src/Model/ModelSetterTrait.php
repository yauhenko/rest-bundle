<?php

namespace Yauhenko\RestBundle\Model;

trait ModelSetterTrait {

	public function setModelData(array $data): void {
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
