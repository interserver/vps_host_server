<?php
namespace App\Command;

use phpDocumentor\Reflection\Php\Project;
use CLIFramework\Command;

class GenerateInternalsCommand extends Command {
	public function brief() {
		return "generate the classes to provide wrappers to internal commands making them usable for scripting";
	}

	/**
	* Convert name to snake_case
	*  Help => help
	*  SuchLong => such_long
	*  hello-my-name-is => hello_my_name_is
	* @param string $className name to convert
	* @return string snake_case translated name
	*/
	public function snakeCase($className)
	{
		$className = lcfirst($className);
		$return = preg_replace_callback('/[A-Z]/', function ($matches) {
			return '_' . strtolower($matches[0]);
		}, $className);
		return $return;
	}

	/**
	* Convert name to kebab-case
	*  Help => help
	*  SuchLong => such-long
	*  hello-my-name-is => hello-my-name-is
	* @param string $className name to convert
	* @return string kebab-case translated name
	*/
	public function kebabCase($className)
	{
		$className = lcfirst($className);
		$return = preg_replace_callback('/[A-Z]/', function ($matches) {
			return '-' . strtolower($matches[0]);
		}, $className);
		return $return;
	}

	/**
	* Convert name to PascalCase
	*  Help => Help
	*  SuchLong => SuchLong
	*  hello-my-name-is => HelloMyNameIs
	* @param string $className name to convert
	* @return string PascalCase translated name
	*/
	public function pascalCase($command)
	{
		$args = explode('-', $command);
		foreach ($args as & $a) {
			$a = ucfirst($a);
		}
		return join('', $args);
	}

	/**
	* Convert name to camelCase
	*  Help => help
	*  SuchLong => suchLong
	*  hello-my-name-is => helloMyNameIs
	* @param string $className name to convert
	* @return string camelCase translated name
	*/
	public function camelCase($command)
	{
		$args = explode('-', $command);
		foreach ($args as $idx =>  & $a) {
			if ($idx > 0)
				$a = ucfirst($a);
		}
		return join('', $args);
	}


	public function execute() {
		$smarty = new \Smarty();
		$smarty->setTemplateDir(['.'])
			->setCompileDir('/home/my/logs/smarty_templates_c')
			->setConfigDir('/home/my/config/smarty_configs')
			->setCacheDir('/home/my/logs/smarty_cache')
			->setCaching(false);
		$smarty->setDebugging(false);
		$smarty->assign('use', 'use App\\Vps;');
		$smarty->assign('brief', 'briefing');
		$smarty->assign('type', 'json');
		$smarty->assign('method', 'somecall()');
		echo $smarty->fetch(__DIR__.'/InternalsCommand/internals.tpl');
		$projectFactory = \phpDocumentor\Reflection\Php\ProjectFactory::createInstance();
		$files = [ new \phpDocumentor\Reflection\File\LocalFile('app/Vps.php') ];
		/** @var Project $project */
		$project = $projectFactory->create('MyProject', $files);
		/** @var \phpDocumentor\Reflection\Php\Class_ $class */
		foreach ($project->getFiles()['app/Vps.php']->getClasses() as $classFullName => $class) {
			$className = $class->getFqsen()->getName();
			echo "- {$classFullName}\n";
			/** @var \phpDocumentor\Reflection\Php\Method_ $method */
			foreach ($class->getMethods() as $methodFullName => $method) {
				$docblock = $method->getDocBlock();
				$methodName = $method->getName();
				$arguments = $method->getArguments();
				$returnType = $method->getReturnType();
				echo "  - normal name: {$methodName}\n";
				echo "  - {$methodFullName}\n";
				if (!is_null($docblock)) {
					$context = $docblock->getContext();
					$description = $docblock->getDescription();
					$summary = $docblock->getSummary();
					$smarty->assign('brief', $summary);
					$smarty->assign('pascal', $this->pascalCase($methodName));
					$smarty->assign('camel', $this->camelCase($methodName));
					$smarty->assign('camel', $this->camelCase($methodName));
					$smarty->assign('snake', $this->snakeCase($methodName));
					$smarty->assign('kebab', $this->kebabCase($methodName));
					$smarty->assign('method', str_replace('\\App\\Vps', 'Vps', $methodFullName));
					file_put_contents(__DIR__.'/InternalsCommand/'.$this->pascalCase($methodName).'Command.php', $smarty->fetch(__DIR__.'/InternalsCommand/internals.tpl'));
					//echo "    - context: {$context}\n";
					//echo "    - description: {$description}\n";
					//echo "    - summary: {$summary}\n";
					$tags = $docblock->getTags();
					/*
					$tags = $docblock->getTags();
					$tag = $tags[0];
					$tag->getName();
					$tag->getType();
					$desc = $tag->getDescription();
					$desc->getBodyTemplate();
					$desc->getTags();
					*/
				}
			}
		}
	}
}

