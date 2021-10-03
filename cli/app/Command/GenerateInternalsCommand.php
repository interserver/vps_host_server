<?php
namespace App\Command;

use phpDocumentor\Reflection\Php\Project;
use CLIFramework\Command;

class GenerateInternalsCommand extends Command {
	public function brief() {
		return "generate the classes to provide wrappers to internal commands making them usable for scripting";
	}

	public function execute() {
		$smarty = new \Smarty();
		$smarty->setTemplateDir(['.'])
			->setCompileDir('/home/my/logs/smarty_templates_c')
			->setConfigDir('/home/my/config/smarty_configs')
			->setCacheDir('/home/my/logs/smarty_cache')
			->setCaching(false);
		$smarty->setDebugging(false);
		$smarty->assign('use', '');
		$smarty->assign('brief', 'briefing');
		$smarty->assign('type', 'json');
		$smarty->assign('method', 'somecall()');
		echo $smarty->fetch(__DIR__.'/internals.tpl');
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
				echo "  - {$methodFullName}\n";
				if (!is_null($docblock)) {
					$context = $docblock->getContext();
					$description = $docblock->getDescription();
					$summary = $docblock->getSummary();
					echo "    - {$summary}\n";
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

