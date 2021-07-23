<?php

namespace Yauhenko\RestBundle;

use Yauhenko\RestBundle\Service\TypeScript;

interface TypesInterface {

	public static function registerTypes(TypeScript $ts): void;

	public static function codePostProcessor(string $code): string;

}
