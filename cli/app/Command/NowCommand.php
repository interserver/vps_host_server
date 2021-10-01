<?php
namespace App\Command;

use CLIFramework\Command;
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
		$this->getLogger()->newline();
		$this->progressbar();
		$this->getLogger()->newline();
		return;
		$this->getLogger()->newline();
		$this->tableFromArray();
		$this->getLogger()->newline();
		$this->formattingTable();
		$this->getLogger()->newline();
		$this->getLogger()->newline();
		$this->tableSimple();
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
		$total = 512;
		for ($i = 0; $i <= $total; $i++) {
		    usleep(10000); //Simulate downloading file.
		    $progress->updateLayout();
		    $progress->update($i, $total);
		}
		$progress->finish();
		$this->getLogger()->writeln('finished progress bar');
	}

	public function tableFromArray() {
		$json = json_decode('{"type":"virtuozzo","veid":"2304178","numproc":"-","status":"stopped","ip":"67.211.213.90","hostname":"emeraldkingdoms.xyz","vswap":"-","layout":"5","kmemsize":"-","kmemsize_f":"-","lockedpages":"-","lockedpages_f":"-","privvmpages":"-","privvmpages_f":"-","shmpages":"-","shmpages_f":"-","numproc_f":"-","physpages":"-","physpages_f":"-","vmguarpages":"-","vmguarpages_f":"-","oomguarpages":"-","oomguarpages_f":"-","numtcpsock":"-","numtcpsock_f":"-","numflock":"-","numflock_f":"-","numpty":"-","numpty_f":"-","numsiginfo":"-","numsiginfo_f":"-","tcpsndbuf":"-","tcpsndbuf_f":"-","tcprcvbuf":"-","tcprcvbuf_f":"-","othersockbuf":"-","othersockbuf_f":"-","dgramrcvbuf":"-","dgramrcvbuf_f":"-","numothersock":"-","numothersock_f":"-","dcachesize":"-","dcachesize_f":"-","numfile":"-","numfile_f":"-","numiptent":"-","numiptent_f":"-","diskspace":"83297160","diskspace_s":"92757672","diskspace_h":"92757672","diskinodes":"55804","diskinodes_s":"5898240","diskinodes_h":"5898240","laverage":"-","vnc":5919,"diskused":"83297160","diskmax":"92757672"}', true);
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

	public function tableSimple() {
		$table = new Table;
		$table->setHeaders([ 'Published Date', 'Title', 'Description' ]);
		$table->addRow(array(
			"September 16, 2014",
			"Zero to One: Notes on Startups, or How to Build the Future",
			"If you want to build a better future, you must believe in secrets.
			The great secret of our time is that there are still uncharted frontiers to explore and new inventions to create. In Zero to One, legendary entrepreneur and investor Peter Thiel shows how we can find singular ways to create those new things. ",
			29.5
		));
		$table->addRow(array(
			"November 4, 2014",
			"Hooked: How to Build Habit-Forming Products",
			["Why do some products capture widespread attention while others flop? What makes us engage with certain products out of sheer habit? Is there a pattern underlying how technologies hook us? "
				, "Nir Eyal answers these questions (and many more) by explaining the Hook Model—a four-step process embedded into the products of many successful companies to subtly encourage customer behavior. Through consecutive “hook cycles,” these products reach their ultimate goal of bringing users back again and again without depending on costly advertising or aggressive messaging."],
			99,
		));
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
		// $table->setStyle(new MarkdownTableStyle);
		$table->addRow(array(
			"September 16, 2014",
			[$redhighlight, "Zero to One: Notes on Startups, or How to Build the Future"],
			"If you want to build a better future, you must believe in secrets.
			The great secret of our time is that there are still uncharted frontiers to explore and new inventions to create. In Zero to One, legendary entrepreneur and investor Peter Thiel shows how we can find singular ways to create those new things. ",
			29.5
		));
		$table->addRow(array(
			"November 4, 2014",
			"Hooked: How to Build Habit-Forming Products",

			"Why do some products capture widespread attention while others flop? What makes us engage with certain products out of sheer habit? Is there a pattern underlying how technologies hook us? "
			. "Nir Eyal answers these questions (and many more) by explaining the Hook Model—a four-step process embedded into the products of many successful companies to subtly encourage customer behavior. Through consecutive “hook cycles,” these products reach their ultimate goa
			l of bringing users back again and again without depending on costly advertising or aggressive messaging.\n",
			99,
		));
		echo $table->render();
	}
}
