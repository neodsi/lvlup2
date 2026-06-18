<?php

declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Profile;
use App\Entity\School;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

class PdfService
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Render invoice.html.twig to PDF, save to var/uploads/{team_id}/invoices/{invoice_id}.pdf.
     *
     * Returns the absolute file path.
     */
    public function generateInvoice(Order $order, School $school, Profile $profile): string
    {
        // Fetch the invoice associated with the order
        /** @var Invoice|null $invoice */
        $invoice = null;
        // Invoice is fetched via Twig context; pass order.id to template and locate via relation.
        // The PDF path uses order.id as fallback when invoice entity is not injected.
        // Callers that have the Invoice entity should pass it through $order context in the template.

        $html = $this->twig->render('pdf/invoice.html.twig', [
            'order'   => $order,
            'school'    => $school,
            'profile' => $profile,
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfContent = $dompdf->output();

        // Determine output path: var/uploads/{team_id}/invoices/{order_id}.pdf
        $invoiceDir = sprintf(
            '%s/var/uploads/%s/invoices',
            $this->projectDir,
            $school->getId(),
        );

        if (!is_dir($invoiceDir)) {
            mkdir($invoiceDir, 0775, true);
        }

        $filePath = sprintf('%s/%s.pdf', $invoiceDir, $order->getId());
        file_put_contents($filePath, $pdfContent);

        return $filePath;
    }

    /**
     * Render invoice to PDF using an explicit Invoice entity (preferred when available).
     * Saves to var/uploads/{team_id}/invoices/{invoice_id}.pdf.
     *
     * Returns the absolute file path.
     */
    public function generateInvoiceFromInvoice(Invoice $invoice, Order $order, School $school, Profile $profile): string
    {
        $html = $this->twig->render('pdf/invoice.html.twig', [
            'invoice' => $invoice,
            'order'   => $order,
            'school'    => $school,
            'profile' => $profile,
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfContent = $dompdf->output();

        $invoiceDir = sprintf(
            '%s/var/uploads/%s/invoices',
            $this->projectDir,
            $school->getId(),
        );

        if (!is_dir($invoiceDir)) {
            mkdir($invoiceDir, 0775, true);
        }

        $filePath = sprintf('%s/%s.pdf', $invoiceDir, $invoice->getId());
        file_put_contents($filePath, $pdfContent);

        return $filePath;
    }
}
