<?php

namespace Yauhenko\RestBundle\Service;

use DateTime;
use Exception;
use Throwable;
use ReflectionClass;
use DateTimeInterface;
use ReflectionProperty;
use ReflectionNamedType;
use ReflectionUnionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Doctrine\Common\Collections\Collection;
use Yauhenko\RestBundle\Attributes\Common\Name;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ObjectBuilder {

	protected ValidatorInterface $validator;
	protected TranslatorInterface $translator;
	protected EntityManagerInterface $entityManager;

	public function __construct(ValidatorInterface $validator, TranslatorInterface $translator, EntityManagerInterface $entityManager) {
		$this->validator = $validator;
		$this->translator = $translator;
		$this->entityManager = $entityManager;
	}

	public function build(string $className, array $data = [], callable $resolve = null): object {
		$rc = new ReflectionClass($className);
		$object = $rc->newInstanceWithoutConstructor();
		$defaults = $rc->getDefaultProperties();
		foreach($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $rp) {
			/** @var ReflectionNamedType|ReflectionUnionType|null $type */
			$type = $rp->getType();
			$key = $rp->getName();
			$isSet = true;
			if(key_exists($key, $data)) {
				$value = $data[$key];
			} elseif(key_exists($key, $defaults)) {
				$value = $defaults[$key];
			} else {
				$value = null;
				$isSet = false;
			}
			if($type->getName() !== gettype($value)) {
				if($type->isBuiltin()) {
					$value = $this->cast($value, $type->getName());
				} elseif(is_array($value)) {
					$value = $this->build($type->getName(), $value);
				}
			}

			$asserts = [];

			// Processing Attributes
			foreach($rp->getAttributes() as $a) {
				$a = $a->newInstance();
				if($a instanceof Constraint) {
					$asserts[] = $a;
				}
			}

			if($name = $rp->getAttributes(Name::class)) {
				$name = $name[0]->newInstance();
			}

			$err = $this->validator->validate($value, $asserts);
			if($err->count()) {
				$err = $err->get(0);
				throw new Exception($this->translator->trans($name ? $name->__toString() : $key) .
					': ' . $err->getMessage());
			}

			if($resolve) {
				$value = call_user_func($resolve, $key, $value);
			}

			if($isSet) $object->$key = $value;
		}
		return $object;
	}

	protected function cast($value, string $type): float|DateTime|null|int|bool|array|string {
		if($value === null) {
			return null;
		} elseif($type === 'bool' || $type === 'boolean') {
			return (bool)$value;
		} elseif($type === 'int' || $type === 'integer') {
			return (int)$value;
		} elseif($type === 'float' || $type === 'double') {
			return (float)$value;
		} elseif($type === 'str' || $type === 'string') {
			return (string)$value;
		} elseif($type === 'array') {
			return (array)$value;
		} elseif($type === DateTimeInterface::class) {
			return new DateTime($value);
		} elseif($type === 'mixed') {
			if(is_numeric($value)) return (float)$value;
			elseif(is_array($value) && $value['id']) {
				if(is_numeric($value['id'])) return (int)$value['id'];
				else return (string)$value['id'];
			} else return $value;
		} else {
			throw new Exception('Failed to convert. Unexpected format: ' . $type);
		}
	}

	public function getOne($id, string $className): mixed {
		if($id === null) return null;
		/** @var mixed $item */
		$item = $this->entityManager->find($className, $id);
		if(!$item) throw new Exception('Entity not found: ' . $className);
		return $item;
	}

	public function getMany(array $ids, string $className): Collection {
		$collection = new ArrayCollection();
		foreach($ids as $id) {
			if($id === null) continue;
			$collection->add($this->getOne($id, $className));
		}
		return $collection;
	}

	public function fillObject(object $object, object $source, callable $resolver = null): void {
		foreach(get_object_vars($source) as $key => $value) {
			if($resolver) [$key, $value] = call_user_func($resolver, $key, $value, $this);
			$setter = "set{$key}";
			if(method_exists($object, $setter)) {
				call_user_func([$object, $setter], $value);
			} elseif(property_exists($object, $key)) {
				$object->{$key} = $value;
			}
		}
	}

	public static function isPropertyDefined(object $object, string $property): bool {
		try {
			/** @noinspection PhpExpressionResultUnusedInspection */
			$object->$property;
			return true;
		} catch(Throwable) {
			return false;
		}
	}

}
