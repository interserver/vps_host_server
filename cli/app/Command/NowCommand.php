<?php
namespace App\Command;

use CLIFramework\Command;
use CLIFramework\Component\Table\Table;
use CLIFramework\Component\Table\TableStyle;
use CLIFramework\Component\Table\CellAttribute;
use CLIFramework\Component\Table\CurrencyFormatCell;
use CLIFramework\Component\Table\MarkdownTableStyle;
use CLIFramework\Formatter;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Component\Progress\ProgressBar;
use DateTime;
use DateTimeInterface;

class NowCommand extends Command {
	public function brief() {
		return "Samples of What the CLI Lib can do";
	}

	public function execute() {
		$this->samples();
	}

	public function samples() {
		$this->getLogger()->writeln("Current Logger\nLevel:".$this->getLogger()->level);
		$this->getLogger()->critical("[1] critical message");
		$this->getLogger()->error("[2] error message");
		$this->getLogger()->warn("[3] warn message");
		$this->getLogger()->info("[4] info message");
		$this->getLogger()->info2("[5] info2 message");
		$this->getLogger()->debug("[6] debug message");
		$this->getLogger()->debug2("[7] debug2 message");
		$this->getLogger()->notice("[a] notice messsage");
		$this->getLogger()->writeln('[b] writeln message');
		$this->getLogger()->newline();
		$this->progressbar();
		$this->getLogger()->newline();
		$this->tableFromArray();
		$this->getLogger()->newline();
		$this->formattingTable();
		$this->getLogger()->newline();
        $this->tableColored();
		$this->getLogger()->newline();
		$this->lineIndicator();
		$this->getLogger()->newline();
	}

    public function lineIndicator() {
		$this->getLogger()->info('line message');
		$indicator = new LineIndicator;
		echo PHP_EOL, $indicator->indicateFile(__FILE__, __LINE__);
	}

	public function progressbar() {
		$progress = new ProgressBar(STDOUT);
		$progress->setUnit('bytes');
		$progress->start('downloading file');
		$total = 120;
		for ($i = 0; $i <= $total; $i++) {
		    usleep(5000); //Simulate downloading file.
		    $progress->updateLayout();
		    $progress->update($i, $total);
		}
		$progress->finish();
		$this->getLogger()->writeln('finished progress bar');
	}

	public function tableFromArray() {
		$json = json_decode('{"type":"virtuozzo","veid":"2304178"}', true);
		$table = new Table;
		//$table->setStyle(new MarkdownTableStyle());
		$table->setHeaders(['Field', 'Value']);
		foreach ($json as $field => $value) {
			if ($value != '-')
				$table->addRow([ucwords(str_replace(['_', 'disk'], [' ', 'disk '], $field)), $value]);
		}
		echo $table->render();
	}

	public function formattingTable() {
		$table = new Table;
		$table->setStyle(new MarkdownTableStyle());
		$table->setHeaders(['Style', 'Example']);
		echo $this->getLogger()->writeln('Testing message formatting');
		$styles = ['red', 'green', 'white', 'yellow', 'strong_red', 'strong_green', 'strong_white' ];
		$formatter = new Formatter;
		foreach ($styles as $style) {
			$table->addRow([$style,  $formatter->format('Here is some styled text', $style)]);
		}
		echo $table->render();
	}

	public function tableColored() {
		$bluehighlight = new CellAttribute;
		$bluehighlight->setBackgroundColor('blue');
		$redhighlight = new CellAttribute;
		$redhighlight->setBackgroundColor('red');
		$priceCell = new CurrencyFormatCell('fr', 'EUR');
		$table = new Table;
		$table->setColumnCellAttribute(0, $bluehighlight);
		$table->setColumnCellAttribute(3, $priceCell);
		$table->setHeaders([ 'Published Date', 'Title', 'Description' ]);
		$table->addRow(["September 16, 2014", [$redhighlight, "Zero to One: Notes on Startups, or How to Build the Future"], "If you want to build a better future, you must believe in secrets.", 29.5]);
		$table->addRow(["November 4, 2014", "Hooked: How to Build Habit-Forming Products", "Why do some products capture widespread attention", 99]);
		echo $table->render();
	}
}
