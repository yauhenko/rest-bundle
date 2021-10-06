<?php

namespace Yauhenko\RestBundle\Service;

use Exception;
use ReflectionClass;
use ReflectionNamedType;
use Doctrine\Common\Annotations\AnnotationReader;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;
use Yauhenko\RestBundle\Attributes\TypeScript\NotNull;
use Yauhenko\RestBundle\Attributes\TypeScript\Undefined;
use Yauhenko\RestBundle\Attributes\TypeScript\Definition;

class TypeScript {

	protected array $definitions = [];
	protected AnnotationReader $reader;
	protected ClassResolver $classResolver;
	protected array $groups = [];

	protected const MAPPING = [
		'mixed' => 'any',
		'int' => 'number',
		'float' => 'number',
		'bool' => 'boolean',
		'array' => '[]',
		'DateTimeInterface' => 'TDateTime',
		'DateTime' => 'TDateTime',
		'DateTimeZone' => 'string',
	];

	public static function factory(): self {
		return new self();
	}

	public function __construct() {
		$this->reader = new AnnotationReader;
		$this->classResolver = new ClassResolver;
		$this->registerType('TDateTime', 'string');
		$this->registerType('TIdentifier', 'string | number');
	}

	public function registerType(string $name, string $type): self {
		$this->registerRaw($name, 'export type ' . $name . ' = ' . $type . ';');
		return $this;
	}

	public function registerTypeOf(string $name, array $values): self {
		array_walk($values, function(&$value) {
			$value = json_encode($value);
		});
		$definition = 'export type ' . $name . ' = ' . implode(' | ', $values) . ';';
		return $this->registerRaw($name, $definition);
	}

	public function registerEnum(string $name, array $enum): self {
		$definition = [];
		foreach($enum as $key => $value) {
			if(is_numeric($key)) {
				$definition[] = $value;
			} else {
				$definition[] = "{$key} = " . (is_numeric($value) ? $value : json_encode($value));
			}
		}
		$definition = 'export enum ' . $name . ' { ' . implode(', ', $definition) . ' };';
		return $this->registerRaw($name, $definition);
	}

	public function registerObject(string $name, string $type, array $data, ?array $values = null): self {
		if(isset($values)) $data = array_combine($data, $values);
		$definition = [];
		foreach($data as $key => $value) {
			$key = json_encode($key);
			$definition[] = "{$key}: '{$value}'";
		}
		$this->registerRaw($name, "export const {$name}: {$type} = { " . implode(', ', $definition) . " };");
		return $this;
	}

	public function registerInterface(string $class): self {
		if(!class_exists($class)) throw new Exception('Invalid class: ' . $class);
		$slug = $this->getSlug($class);
		$this->registerRaw($slug, $this->getInterfaceDefinition($class));

		// Groups parsing

		$rc = new ReflectionClass($class);
		$reader = new AnnotationReader;

		foreach($rc->getMethods() as $rm) {
			/** @var Groups $a */
			if($a = $reader->getMethodAnnotation($rm, Groups::class)) {
				foreach($a->getGroups() as $group) {
					if(!in_array($group, $this->groups)) {
						$this->groups[] = $group;
					}
				}
			}
			foreach($rm->getAttributes(Groups::class) as $a) {
				foreach($a->getArguments() as $groups) {
					foreach($groups as $group) {
						if(!in_array($group, $this->groups)) {
							$this->groups[] = $group;
						}
					}
				}
			}
		}

		foreach($rc->getProperties() as $rp) {
			/** @var Groups $a */
			if($a = $reader->getPropertyAnnotation($rp, Groups::class)) {
				foreach($a->getGroups() as $group) {
					if(!in_array($group, $this->groups)) {
						$this->groups[] = $group;
					}
				}
			}
			foreach($rp->getAttributes(Groups::class) as $a) {
				foreach($a->getArguments() as $groups) {
					foreach($groups as $group) {
						if(!in_array($group, $this->groups)) {
							$this->groups[] = $group;
						}
					}
				}
			}
		}

		// Groups parsing end

		return $this;
	}

	public function registerRaw(string $name, string $definition): self {
		if(isset($this->definitions[$name]))
			throw new Exception('Duplicate definition: ' . $name);
		$this->definitions[$name] = $definition;
		return $this;
	}

	public function getInterfaceDefinition(?string $class): string {
		if(!$class) return '';
		$rc = new ReflectionClass($class);
		/** @var Definition|null $T */
		$T = $this->classResolver->getAttribute($rc, Definition::class);
		$definition = '';// ' . $class . PHP_EOL;
		$definition .= 'export interface ' . $this->getSlug($class)  . ($T ? ($T->getValue() ? '<T>' : null) : null) . ' {' . PHP_EOL;

		$defaults = $rc->getDefaultProperties();

		foreach($rc->getProperties() as $rp) {
			$name = $rp->getName();
			if($name === 'request') continue;

			//if(preg_match('/Entity/', $class) && !$groups) continue;

			if(!method_exists($class, 'get' . $name) && !$rp->isPublic() ) continue;
			/** @var ReflectionNamedType $type */
			if($type = $rp->getType()) {
				$nullable = $type->allowsNull();
				$typeName = $type->getName();
			} else {
				$typeName = 'any';
				$nullable = true;
			}

			if(isset(self::MAPPING[$typeName])) {
				$typeName = self::MAPPING[$typeName];
			} elseif(preg_match('/(Entity|Model)/', $typeName)) {
				$typeName = $this->getSlug($typeName);
			}

			if($name === 'id') {
				$name = 'readonly ' . $name;
			}

			if(!$groups = $this->classResolver->getAttribute($rp, Groups::class)) {
				/** @var Groups|null $groups */
				$groups = $this->reader->getPropertyAnnotation($rp, Groups::class);
			}

			if(!$notBlank = $this->classResolver->getAttribute($rp, NotBlank::class)) {
				/** @var NotBlank|null $notBlank */
				$notBlank = $this->reader->getPropertyAnnotation($rp, NotBlank::class);
			}

			$notNull = $this->classResolver->getAttribute($rp, NotNull::class);
			$undefined = $this->classResolver->getAttribute($rp, Undefined::class);
			$T = $this->classResolver->getAttribute($rp, Definition::class);
			$choice = $this->classResolver->getAttribute($rp, Choice::class);

			$q = '';

			if($notNull) $nullable = false;
			if($undefined) $q = '?';
			if(isset($defaults[$name])) $q = '?';
			if(!isset($defaults[$name]) && !$notBlank) $q = '?';
			if($groups && in_array('main', $groups->getGroups())) $q = '';
			if($choice) $typeName = "'" . implode("' | '", $choice->choices) . "'";

			$definition .= "  " . $name . $q .  ': ' . ($T ? $T->value : $typeName)  . ($nullable ? ' | null' : '') . ';' . PHP_EOL;
		}
		$definition .= '}' . PHP_EOL . PHP_EOL;
		return $definition;
	}

	public function getSlug(?string $name): string {
		if($name === 'bool') return 'boolean';
		if($name === 'int' || $name === 'integer' || $name === 'float' || $name === 'double') return 'number';
		if($name === 'mixed') return 'any';
		if(!class_exists($name)) return $name . '';
		if(!$name) return 'null';
		$name = explode('\\', $name);
		$name = array_pop($name);
		return 'I' . $name;
	}

	public function prettify(string $code, string $cacheDir): string {
		$bin = '/usr/bin/prettier';
		if(file_exists($bin)) {
			$tmp = $cacheDir . '/' . uniqid() . '.ts';
			file_put_contents($tmp, $code);
			$out = [];
			exec("{$bin} {$tmp}", $out);
			unlink($tmp);
			return implode("\n", $out);
		} else {
			return $code;
		}
	}

	public function registerInterfacesFromDir(string $dir, string $namespace = 'App'): self {
		foreach($this->classResolver->getNames($dir, $namespace) as $class) {
			$this->registerInterface($class);
		}
		return $this;
	}

	public function getTypeScriptCode(): string {
		return trim(implode(PHP_EOL . PHP_EOL, array_values($this->definitions)) . PHP_EOL . PHP_EOL);
	}

	public function getGroups(): array {
		return $this->groups;
	}

}
