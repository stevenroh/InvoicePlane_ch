<?php
if (!defined('BASEPATH')) exit('No direct script access allowed');

use Sprain\SwissQrBill as QrBill;

/*
 * InvoicePlane
 *
 * @author		InvoicePlane Developers & Contributors
 * @copyright	Copyright (c) 2012 - 2018 InvoicePlane.com
 * @license		https://invoiceplane.com/license.txt
 * @link		https://invoiceplane.com
 */

/**
 * Create a PDF
 *
 * @param $html
 * @param $filename
 * @param bool $stream
 * @param null $password
 * @param null $isInvoice
 * @param null $is_guest
 * @param bool $zugferd_invoice
 * @param null $associated_files
 *
 * @return string
 * @throws \Mpdf\MpdfException
 */
function pdf_create(
    $html,
    $filename,
    $stream = true,
    $password = null,
    $isInvoice = null,
    $is_guest = null,
    $zugferd_invoice = false,
    $associated_files = null,
    $invoice_data = null
) {
    $CI = &get_instance();

    // Get the invoice from the archive if available
    $invoice_array = array();

    // mPDF loading
    if (!defined('_MPDF_TEMP_PATH')) {
        define('_MPDF_TEMP_PATH', UPLOADS_TEMP_MPDF_FOLDER);
        define('_MPDF_TTFONTDATAPATH', UPLOADS_TEMP_MPDF_FOLDER);
    }

    $mpdf = new \Mpdf\Mpdf();

    // mPDF configuration
    $mpdf->useAdobeCJK = true;
    $mpdf->autoScriptToLang = true;
    $mpdf->autoVietnamese = true;
    $mpdf->autoArabic = true;
    $mpdf->autoLangToFont = true;

    if (IP_DEBUG) {
        // Enable image error logging
        $mpdf->showImageErrors = true;
    }

    // Include zugferd if enabled
    if ($zugferd_invoice) {
        $CI->load->helper('zugferd');
        $mpdf->PDFA = true;
        $mpdf->PDFAauto = true;
        $mpdf->SetAdditionalXmpRdf(zugferd_rdf());
        $mpdf->SetAssociatedFiles($associated_files);
    }

    // Set a password if set for the voucher
    if (!empty($password)) {
        $mpdf->SetProtection(array('copy', 'print'), $password, $password);
    }

    // Check if the archive folder is available
    if (!(is_dir(UPLOADS_ARCHIVE_FOLDER) || is_link(UPLOADS_ARCHIVE_FOLDER))) {
        mkdir(UPLOADS_ARCHIVE_FOLDER, '0777');
    }

    // Set the footer if voucher is invoice and if set in settings
    if (!empty($CI->mdl_settings->settings['pdf_invoice_footer']) && $isInvoice) {
        $mpdf->setAutoBottomMargin = 'stretch';
        $mpdf->SetHTMLFooter('<div id="footer">' . $CI->mdl_settings->settings['pdf_invoice_footer'] . '</div>');
    }

    // Set the footer if voucher is quote and if set in settings
    if (!empty($CI->mdl_settings->settings['pdf_quote_footer']) && strpos($filename, trans('quote')) !== false) {
        $mpdf->setAutoBottomMargin = 'stretch';
        $mpdf->SetHTMLFooter('<div id="footer">' . $CI->mdl_settings->settings['pdf_quote_footer'] . '</div>');
    }

    // Watermark
    if (get_setting('pdf_watermark')) {
        $mpdf->showWatermarkText = true;
    }

    if ($invoice_data == null) {
        // invoice_data can be null of it's a quote. So we juste write html (and do not process qrbill)
        $mpdf->WriteHTML((string) $html);
    } else {
        $qrBill = QrBill\QrBill::create();
        // Add creditor information
        // Who will receive the payment and to which bank account?
        $qrBill->setCreditor(
            QrBill\DataGroup\Element\CombinedAddress::create(
                'Company Name',
                'Street',
                'NPA City',
                'CH'
            )
        );

        $qrBill->setCreditorInformation(
            QrBill\DataGroup\Element\CreditorInformation::create(
                'INSERT_CH_QR_CODE_HERE'
                // This is a special QR-IBAN.
                // Classic IBANs will not be valid here.
            )
        );

        // Add debtor information
        // Who has to pay the invoice? This part is optional.
        //
        // Notice how you can use two different styles of addresses: CombinedAddress or StructuredAddress.
        // They are interchangeable for creditor as well as debtor.
        $qrBill->setUltimateDebtor(
            QrBill\DataGroup\Element\StructuredAddress::createWithStreet(
                $invoice_data->client_name . ' ' . $invoice_data->client_surname,
                $invoice_data->client_address_1,
                $invoice_data->client_address_2,
                $invoice_data->client_zip,
                $invoice_data->client_city,
                'CH'
            )
        );

        // Add payment amount information
        // What amount is to be paid?
        $qrBill->setPaymentAmountInformation(
            QrBill\DataGroup\Element\PaymentAmountInformation::create(
                'CHF',
                $invoice_data->invoice_total
            )
        );

        // Add payment reference
        // This is what you will need to identify incoming payments.
        $referenceNumber = QrBill\Reference\QrPaymentReferenceGenerator::generate(
            '000000',  // You receive this number from your bank (BESR-ID). Unless your bank is PostFinance, in that case use NULL.
            $invoice_data->invoice_id // A number to match the payment with your internal data, e.g. an invoice number
        );

        $qrBill->setPaymentReference(
            QrBill\DataGroup\Element\PaymentReference::create(
                QrBill\DataGroup\Element\PaymentReference::TYPE_QR,
                $referenceNumber
            )
        );

        // Optionally, add some human-readable information about what the bill is for.
        $qrBill->setAdditionalInformation(
            QrBill\DataGroup\Element\AdditionalInformation::create(
                $invoice_data->invoice_number
            )
        );

        $mpdf->WriteHTML((string) $html);

        if ($mpdf->_getHtmlHeight($html) > 180) {
            $mpdf->AddPage();
        }

        $output = new QrBill\PaymentPart\Output\MpdfOutput\MpdfOutput($qrBill, 'fr', $mpdf);
        $output
            ->setPrintable(false)
            ->getPaymentPart();
    }

    if ($isInvoice) {

        foreach (glob(UPLOADS_ARCHIVE_FOLDER . '*' . $filename . '.pdf') as $file) {
            array_push($invoice_array, $file);
        }

        if (!empty($invoice_array) && !is_null($is_guest)) {
            rsort($invoice_array);

            if ($stream) {
                return $mpdf->Output($filename . '.pdf', 'I');
            } else {
                return $invoice_array[0];
            }
        }

        $archived_file = UPLOADS_ARCHIVE_FOLDER . date('Y-m-d') . '_' . $filename . '.pdf';
        $mpdf->Output($archived_file, 'F');

        if ($stream) {
            return $mpdf->Output($filename . '.pdf', 'I');
        } else {
            return $archived_file;
        }
    }

    // If $stream is true (default) the PDF will be displayed directly in the browser
    // otherwise will be returned as a download
    if ($stream) {
        return $mpdf->Output($filename . '.pdf', 'I');
    } else {
        $mpdf->Output(UPLOADS_TEMP_FOLDER . $filename . '.pdf', 'F');
        return UPLOADS_TEMP_FOLDER . $filename . '.pdf';
    }
}
