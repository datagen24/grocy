<?php

namespace Victual\Services;

use DateTime;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\Printer;

/**
 * Prints the shopping list on an ESC/POS thermal printer, reached over the network or
 * a local device file depending on the VICTUAL_TPRINTER_* settings.
 */
class PrintService extends BaseService
{
	/**
	 * Prints the given lines and cuts the paper.
	 *
	 * @param bool $printHeader Whether to print the "Victual" banner and timestamp first
	 * @param string[] $lines Pre-rendered text lines, one per shopping list item
	 * @return array Always ['result' => 'OK']; failures throw instead
	 * @throws \Exception When the printer cannot be reached
	 */
	public function printShoppingList(bool $printHeader, array $lines): array
	{
		$printer = self::getPrinterHandle();
		if ($printer === false)
		{
			throw new \Exception('Unable to connect to printer');
		}

		if ($printHeader)
		{
			self::printHeader($printer);
		}

		foreach ($lines as $line)
		{
			$printer->text($line);
			$printer->feed();
		}

		$printer->feed(3);
		$printer->cut();
		$printer->close();
		return [
			'result' => 'OK'
		];
	}

	/**
	 * Connects to the configured printer: TCP (VICTUAL_TPRINTER_IP/PORT) when
	 * VICTUAL_TPRINTER_IS_NETWORK_PRINTER, otherwise a device file (VICTUAL_TPRINTER_CONNECTOR).
	 */
	private static function getPrinterHandle()
	{
		if (VICTUAL_TPRINTER_IS_NETWORK_PRINTER)
		{
			$connector = new NetworkPrintConnector(VICTUAL_TPRINTER_IP, VICTUAL_TPRINTER_PORT);
		}
		else
		{
			$connector = new FilePrintConnector(VICTUAL_TPRINTER_CONNECTOR);
		}
		return new Printer($connector);
	}

	/**
	 * Prints the centered "Victual" banner followed by the current date/time (d/m/Y H:i).
	 */
	private static function printHeader(Printer $printer)
	{
		$date = new DateTime();
		$dateFormatted = $date->format('d/m/Y H:i');

		$printer->setJustification(Printer::JUSTIFY_CENTER);
		$printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
		$printer->setTextSize(4, 4);
		$printer->setReverseColors(true);
		$printer->text('Victual');
		$printer->setJustification();
		$printer->setTextSize(1, 1);
		$printer->setReverseColors(false);
		$printer->feed(2);
		$printer->text($dateFormatted);
		$printer->selectPrintMode();
		$printer->feed(2);
	}
}
