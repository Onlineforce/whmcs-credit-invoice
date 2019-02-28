<?php

use WHMCS\Billing\Invoice;
use WHMCS\Database\Capsule;
use WHMCS\Config\Setting;
use WHMCS\Carbon;


function credit_invoice_credit() {
    $settings = Setting::allAsArray();
    $addonSettings = credit_invoice_getconfig();
    $invoiceId = filter_input(INPUT_POST, 'invoice', FILTER_SANITIZE_NUMBER_INT);
    
    $invoice = Invoice::with([
            'items',
            'snapshot'
        ])
        ->findOrFail($invoiceId);

    // Duplicate original invoice (this is the credit note).
    $creditNoteData = [
        'userid' => $invoice->userid,
        'notes' => "Refund Invoice|{$invoiceId}|DO-NOT-REMOVE",
    ];

    foreach ($invoice->items as $idx => $item) {
        $amount = ((bool) $addonSettings['negateInvoice']) ? -$item->amount : $item->amount;
        $creditNoteData['itemdescription' . $idx] = $item->description;
        $creditNoteData['itemamount' . $idx] = $amount;
        $creditNoteData['itemtaxed' . $idx] = $item->taxed;
    }

    $creditNoteResult = localApi('CreateInvoice', $creditNoteData);

    $now = Carbon::now();

    $creditNote = Invoice::with([
            'snapshot',
        ])
        ->findOrFail($creditNoteResult['invoiceid']);

    $creditNote->status = 'Paid';
    $creditNote->paymentmethod = $addonSettings['creditNotePaymentMethod'];
    $creditNote->datepaid = $now;

    // since we manually change status of invoice to 'Paid'
    // we need to set correct invoicenum taking other format fields into account
    if ((bool) $settings['SequentialInvoiceNumbering']) {
        $replace = [
            '{YEAR}' => $now->format('Y'),
            '{MONTH}' => $now->format('m'),
            '{DAY}' => $now->format('d'),
            '{NUMBER}' => $settings['SequentialInvoiceNumberValue'],
        ];

        $creditNote->invoicenum = str_replace(
            array_keys($replace), 
            array_values($replace), 
            $settings['SequentialInvoiceNumberFormat']
        );

        // increment 'SequentialInvoiceNumberValue' value
        $nextInvoiceNum = ($settings['SequentialInvoiceNumberValue'] + $settings['InvoiceIncrement']);
        Setting::setValue('SequentialInvoiceNumberValue', $nextInvoiceNum);
    }

    // copy original client snapshot data if applicable
    if ((bool) $settings['StoreClientDataSnapshotOnInvoiceCreation']) {
        if (!is_null($invoice->snapshot)) {
            $creditNote->snapshot->clientsdetails = $invoice->snapshot->clientsdetails;
            $creditNote->snapshot->customfields = $invoice->snapshot->customfields;
        }
    }

    $creditNote->save();

    // Mark original invoice as paid and add reference to credit note.
    $notes = explode(PHP_EOL, $invoice->adminNotes);
    array_unshift($notes, "Refund Credit Note|{$creditNote->id}|DO-NOT-REMOVE");

    $invoice->status = ((bool) $addonSettings['cancelInvoice']) ? 'Cancelled' : 'Paid';
    $invoice->adminNotes = implode(PHP_EOL, $notes);
    $invoice->save();

    // Finally redirect to our credit note.
    redirect_to_invoice($creditNote->id);
}

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

function credit_invoice_getconfig() {
    $module = basename(dirname(__FILE__));

    $config = Capsule::table('tbladdonmodules')
        ->where('module', $module)
        ->pluck('value', 'setting');

    return $config;
}

function credit_invoice_replace_notes($invoiceNotes, $html=false) {
    $addonSettings = credit_invoice_getconfig();
    $notes = str_replace('<br />', '', $invoiceNotes);
    $notes = explode(PHP_EOL, $notes);

    foreach ($notes as $idx => $note) {
        if (preg_match('/Refund Invoice\|(\d*)/', $note, $match)) {
            // credit note
            $text = $addonSettings['creditNoteNoteText'];
        } elseif (preg_match('/Refund Credit Note\|(\d*)/', $note, $match)) {
            // credited invoice
            $text = $addonSettings['invoiceNoteText'];
        }     

        if ($match) {
            $invoice = Invoice::find($match[1]);
            $invoiceNum = ($invoice->invoicenum) ? $invoice->invoicenum : $match[1];
            $notes[$idx] = str_replace('{NUMBER}', $invoiceNum, $text);
        }
    }

    $return = implode(PHP_EOL, $notes);

    if ($html) {
        $return = nl2br($return);
    }

    return $return;
}


function credit_invoice_replace_pagetitle($invoiceId, $pageTitle) {
    $addonSettings = credit_invoice_getconfig();

    if (empty($addonSettings['creditNotePageTitle'])) {
        $return = $pageTitle;
    } else {
        $invoice = Invoice::findOrFail($invoiceId);
        $invoiceNum = ($invoice->invoicenum) ? $invoice->invoicenum : $invoiceId;
        $return = str_replace('{NUMBER}', $invoiceNum, $addonSettings['creditNotePageTitle']);
    }

    return $return;
}