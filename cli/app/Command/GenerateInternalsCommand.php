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
		$templateFile = __DIR__.'/../Resources/InternalCommand.php.tpl';
		$templateClassFile = __DIR__.'/../Resources/InternalCommandClass.php.tpl';
		$smarty = new \Smarty();
		$smarty->setTemplateDir(['.'])
			->setCompileDir('/home/my/logs/smarty_templates_c')
			->setConfigDir('/home/my/config/smarty_configs')
			->setCacheDir('/home/my/logs/smarty_cache')
			->setCaching(false);
		$smarty->setDebugging(false);
		$dirName = __DIR__.'/InternalsCommand/';
		@mkdir($dirName, 0775, true);
		$files = [];
		foreach (array_merge(glob('app/Vps.php'), glob('app/Os/*.php'), glob('app/Vps/*.php')) as $fileName)
			$files[] = new \phpDocumentor\Reflection\File\LocalFile($fileName);
		$projectFactory = \phpDocumentor\Reflection\Php\ProjectFactory::createInstance();
		/** @var Project $project */
		$project = $projectFactory->create('MyProject', $files);
		//var_dump($project->getFiles());exit;
		/** @var \phpDocumentor\Reflection\Php\File $file */
		foreach ($project->getFiles() as $fileName => $file) {
			echo "File - {$fileName}\n";
			$smarty->assign('fileName', $fileName);
			/** @var \phpDocumentor\Reflection\Php\Class_ $class */
			foreach ($file->getClasses() as $classFullName => $class) {
				$className = $class->getFqsen()->getName();
				echo "- {$classFullName} ({$className})\n";
				$classAssign = [
					'name' => $className,
					'fullName' => $classFullName,
				];
				$smarty->assign('class', $classAssign);
				$dirName = __DIR__.'/InternalsCommand/'.$className.'Command';
				file_put_contents($dirName.'.php', $smarty->fetch($templateClassFile));
				@mkdir($dirName, 0775, true);
				/** @var \phpDocumentor\Reflection\Php\Method_ $method */
				foreach ($class->getMethods() as $methodFullName => $method) {
					$docblock = $method->getDocBlock();
					$methodName = $method->getName();
					$arguments = $method->getArguments();
					$returnType = $method->getReturnType();
					echo "  - {$methodName}\n";
					//echo "  - return type: {$returnType}\n";
					$methodAssign = [
						'name' => $methodName,
						'pascal' => $this->pascalCase($methodName),
						'camel' => $this->camelCase($methodName),
						'snake' => $this->snakeCase($methodName),
						'kebab' => $this->kebabCase($methodName),
					];
					if (!is_null($docblock)) {
						//$context = $docblock->getContext();
						$description = $docblock->getDescription();
						$summary = $docblock->getSummary();
						$methodAssign['summary'] = $summary;
						//echo "    - context: ".var_export($context, true)."\n";
						echo "    - description: {$description}\n";
						echo "    - summary: {$summary}\n";
						$returnTags = $docblock->getTagsByName('return');
						if (count($returnTags) > 0) {
							$returnType = $returnTags[0]->getType();
							$methodAssign['returnType'] = $returnType;
						}
						//echo "		return tags: ".var_export($returnTags,true)."\n";
						$tags = $docblock->getTags();
						foreach ($tags as $idx => $tag) {
							$tagName = $tag->getName();
							$tagType = $tag->getType();
							$tagDesc = $tag->getDescription();
							$descBody = $tagDesc->getBodyTemplate();
							echo "		Tag {$tagName} type {$tagType}\n";
							echo "		tag desc body template {$descBody}\n";
						}
					}
					$smarty->assign('class', $classAssign);
					$smarty->assign('method', $methodAssign);
					file_put_contents($dirName.'/'.$this->pascalCase($methodName).'Command.php', $smarty->fetch($templateFile));
				}
			}
		}
	}
}

