<?php

namespace Yauhenko\RestBundle\Attributes\Api;

use Attribute;
use Symfony\Component\Serializer\Annotation\Groups;

#[Attribute(Attribute::TARGET_METHOD)]
class Method {

	#[Groups(['main'])]
	protected ?string $title = null;

	#[Groups(['main'])]
	protected ?string $description = null;

	protected ?string $request = null;
	protected ?string $response = null;

	public function __construct(?string $title = null, ?string $description = null,
	                            ?string $request = null, ?string $response = null) {
		$this->title = $title;
		$this->description = $description;
		$this->request = $request;
		$this->response = $response;
	}

	public function getTitle(): ?string {
		return $this->title;
	}

	public function getDescription(): ?string {
		return $this->description;
	}

	public function getRequest(): ?string {
		return $this->request;
	}

	public function getResponse(): ?string {
		return $this->response;
	}

}
