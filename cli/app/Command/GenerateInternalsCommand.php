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
		exit;
		$projectFactory = \phpDocumentor\Reflection\Php\ProjectFactory::createInstance();
		$files = [ new \phpDocumentor\Reflection\File\LocalFile('app/Vps.php') ];
		/** @var Project $project */
		$project = $projectFactory->create('MyProject', $files);
		/** @var \phpDocumentor\Reflection\Php\Class_ $class */
		foreach ($project->getFiles()['app/Vps.php']->getClasses() as $class) {
		    echo '- ' . $class->getFqsen() . PHP_EOL;
			/** @var \phpDocumentor\Reflection\Php\Method_ $method */
			foreach ($class->getMethods() as $method) {
				echo "Method: ".$method->getName().PHP_EOL;
				var_export($method->getDocBlock()).PHP_EOL;
			}
		}
		echo "Functions:\n";
		/** @var \phpDocumentor\Reflection\Php\Function_ $class */
		foreach ($project->getFiles()['app/Vps.php']->getFunctions() as $class) {
		    echo '- ' . $class->getFqsen() . PHP_EOL;
		}
		echo "DocBlock:\n";
		/** @var \phpDocumentor\Reflection\Php\Function_ $class */
		foreach ($project->getFiles()['app/Vps.php']->getDocBlock() as $class) {
		    echo '- ' . $class . PHP_EOL;
		}

		$class = $project->getFiles()['app/Vps.php']->getClasses()['\App\Vps'];
		$class->getMethods();
		$class->getMethods()["\App\Vps::getAllVpsAllVirts()"];
		$method = $class->getMethods()["\App\Vps::getAllVpsAllVirts()"];
		$method->getArguments();
		$method->getName;
		$method->getName();
		$method->getReturnType();
		$method->getDocBlock();
		$docblock = $method->getDocBlock();
		$docblock->getContext();
		$docblock->getDescription();
		$docblock->getSummary();
		$tags = $docblock->getTags();
		$tag = $tags[0];
		$tag->getName();
		$tag->getType();
		$desc = $tag->getDescription();
		$desc->getBodyTemplate();
		$desc->getTags();
	}
}

