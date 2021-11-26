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
use ReflectionException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Doctrine\Common\Collections\Collection;
use Yauhenko\RestBundle\Attributes\Common\Name;
use Doctrine\Common\Collections\ArrayCollection;
use Yauhenko\RestBundle\Attributes\Api\Validator;
use Yauhenko\RestBundle\Attributes\Api\Processor;
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

	/**
	 * @template T
	 * @param class-string $className
	 * @param array $data
	 * @param callable|null $resolve
	 * @return T
	 * @throws ReflectionException
	 */
	public function build(string $className, array $data = [], callable $resolve = null): object {
		$rc = new ReflectionClass($className);
		$object = $rc->newInstanceWithoutConstructor();
		$defaults = $rc->getDefaultProperties();
		foreach($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $rp) {
			/** @var ReflectionNamedType|ReflectionUnionType|null $type */
			$type = $rp->getType();

            if($type instanceof ReflectionUnionType) {
                $type = $type->getTypes()[0];
            }

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

			if($name = $rp->getAttributes(Name::class)) {
				$name = $name[0]->newInstance();
			} else {
				$name = $key;
			}

			$name = $this->translator->trans($name);

			if($processors = $rp->getAttributes(Processor::class)) {
				foreach($processors as $pa) {
					/** @var Processor $pi */
					$pi = $pa->newInstance();
					try {
						$processor = $pi->getProcessor();
						if(is_string($processor)) $processor = [$object, $processor];
						$value = call_user_func($processor, $value);
					} catch(Throwable $err) {
						throw new Exception($name . ': ' . $err->getMessage());
					}
				}
			}

			if($validators = $rp->getAttributes(Validator::class)) {
				foreach($validators as $va) {
					/** @var Validator $vi */
					$vi = $va->newInstance();
					try {
						$validator = $vi->getValidator();
						if(is_string($validator)) $validator = [$object, $validator];
						call_user_func($validator, $value);
					} catch(Throwable $err) {
						throw new Exception($name . ': ' . $err->getMessage());
					}
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

			$err = $this->validator->validate($value, $asserts);
			if($err->count()) {
				$err = $err->get(0);
				throw new Exception($name . ': ' . $err->getMessage());
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

	/**
	 * @template T
	 * @param string|int|null $id
	 * @param class-string<T> $className
	 * @return T
	 * @throws Exception
	 */
	public function getOne(int|string|null $id, string $className): mixed {
		if($id === null) return null;
		/** @var mixed $item */
		$item = $this->entityManager->find($className, $id);
		if(!$item) throw new Exception('Entity not found: ' . $className);
		return $item;
	}

	/**
	 * @template T
	 * @param array $ids
	 * @param class-string<T> $className
	 * @return Collection<T>
	 * @throws Exception
	 */
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
				$object->$key = $value;
			}
		}
	}

	public function getValue(object $object, string $property, mixed $defaultValue = null): mixed {
		if(self::isPropertyDefined($object, $property)) {
			return $object->$property;
		} else {
			return $defaultValue;
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

    public function doIfDefined(object $object, string $property, callable $action) {
        if(self::isPropertyDefined($object, $property)) {
            $value = $object->$property;
            call_user_func($action, $value);
        }
    }

}
