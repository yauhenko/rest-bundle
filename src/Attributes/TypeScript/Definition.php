<?php

namespace Yauhenko\RestBundle\Attributes\TypeScript;

use Attribute;
use Symfony\Component\Serializer\Annotation\Groups;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
class Definition {

	#[Groups(['main'])]
	public string $value = 'T';

	public function __construct(string $value = 'T') {
		$this->value = $value;
	}

	public function __toString(): string {
		return $this->value;
	}

	public function getValue(): string {
		return $this->value;
	}

}
