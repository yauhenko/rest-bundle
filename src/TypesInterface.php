<?php

namespace Yauhenko\RestBundle;

use Yauhenko\RestBundle\Service\TypeScript;

interface TypesInterface {

	public static function register(TypeScript $ts): void;

}
