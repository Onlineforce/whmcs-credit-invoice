<?php

use \WHMCS\Billing\Invoice;
use \WHMCS\Billing\Invoice\Item;
use WHMCS\Database\Capsule;

function credit_invoice_credit() {
	$invoiceId = filter_input(INPUT_POST, 'invoice', FILTER_SANITIZE_NUMBER_INT);
	$invoice = Invoice::with('items')->findOrFail($invoiceId);

	// Duplicate original invoice (this is the credit note).
	$credit = $invoice->replicate();
	$credit->subtotal = -$credit->subtotal;
	$credit->tax = -$credit->tax;
	$credit->total = -$credit->total;
	$credit->adminNotes = "Refund Invoice|{$invoiceId}|DO-NOT-REMOVE";
	$credit->dateCreated = Carbon\Carbon::now();
	$credit->dateDue = Carbon\Carbon::now();
	$credit->datePaid = Carbon\Carbon::now();
	$credit->status = 'Paid';
	$credit->save();

	// Copy old invoice items to credit note
	$oldItems = Capsule::table('tblinvoiceitems')->where('invoiceid', '=', $invoice->id)->get();
	$newItems = [];
	foreach ($oldItems as $item) {
		$newItems[] = [
			'invoiceid' => $credit->id,
			'userid' => $credit->userid,
			'description' => $item->description,
			'amount' => -$item->amount,
		];
	}

	// Add a new item to credit note, describing that this is a credit
	$newItems[] = [
		'invoiceid' => $credit->id,
		'userid' => $credit->userid,
		'description' => "Kreditfaktura avser faktura #{$invoiceId}",
		'amount' => 0,
	];
	Capsule::table('tblinvoiceitems')->insert($newItems);

	// Mark original invoice as paid and add reference to credit note.
	$invoice->status = 'Paid';
	$invoice->adminNotes = $invoice->adminNotes . PHP_EOL . "Refund Credit Note|{$credit->id}|DO-NOT-REMOVE";
	$invoice->save();

	// Finally redirect to our credit note.
	redirect_to_invoice($credit->id);
};

function invoice_is_credited($invoiceId) {
	$invoice = Invoice::findOrFail($invoiceId);
	preg_match('/Refund Credit Note\|(\d*)/', $invoice->adminNotes, $match);
	return $match;
}

function invoice_is_creditnote($invoiceId) {
	$invoice = Invoice::findOrFail($invoiceId);
	preg_match('/Refund Invoice\|(\d*)/', $invoice->adminNotes, $match);
	return $match;
}

function redirect_to_invoice($invoiceId) {
	header("Location: invoices.php?action=edit&id={$invoiceId}");
	die();
}