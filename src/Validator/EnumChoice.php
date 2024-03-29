<?php

namespace Yauhenko\RestBundle\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

#[Attribute]
class EnumChoice extends Constraint {

    public function __construct(
        public string $enum,
        public string $message = 'Неверное значение {{ value }}. Допустимые: {{ options }}'
    ) {
        parent::__construct();
    }

    public function validatedBy(): string {
        return EnumChoiceValidator::class;
    }

}
