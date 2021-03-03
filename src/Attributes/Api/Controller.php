<?php

namespace Yauhenko\RestBundle\Attributes\Api;

use Attribute;
use Symfony\Component\Serializer\Annotation\Groups;

#[Attribute(Attribute::TARGET_CLASS)]
class Controller {

	#[Groups(['main'])]
	protected ?string $title = null;

	#[Groups(['main'])]
	protected ?string $description = null;

	public function __construct(?string $title = null, ?string $description = null) {
		$this->title = $title;
		$this->description = $description;
	}

	public function getTitle(): ?string {
		return $this->title;
	}

	public function getDescription(): ?string {
		return $this->description;
	}

}
