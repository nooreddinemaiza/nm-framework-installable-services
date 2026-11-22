<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use NM\Services\SalesService\Sales\Domain\Money;
use NM\Services\SalesService\Sales\Domain\SalesOrder;
use NM\Services\SalesService\Sales\Domain\SalesOrderState;
use Core\QrCode\QrCode;
use Core\Support\PDF\Document;
use Core\Support\PDF\Font\StandardFont;
use Core\Support\PDF\Text\Utf8;

final class SalesOrderPdfService
{
    public function render(SalesOrder $order): string
    {
        $document = (new Document())->setMetadata([
            'title' => 'Commande #' . $order->id,
            'subject' => 'Détail de commande client',
            'creator' => 'Service Ventes',
        ]);
        $font = StandardFont::helvetica();
        $page = $document->addPage();
        $page->setFont($font, 20)->setColor('#0f766e')->text('Commande #' . $order->id, 48, 62);
        $page->setFont($font, 11)->setColor('#1e293b');
        $page->text('État : ' . self::statusLabel($order->status), 48, 91);
        $page->text('Édité le : ' . gmdate('Y-m-d H:i:s') . ' UTC', 48, 109);
        if ($order->publicReference !== null) {
            $page->text('Référence de suivi : ' . $order->publicReference, 48, 127);
        }
        $checkout = is_array($order->details['checkout'] ?? null) ? $order->details['checkout'] : [];
        $customer = is_array($checkout['customer'] ?? null) ? $checkout['customer'] : [];
        $page->text('Client : ' . (string) ($customer['name'] ?? 'Non renseigné'), 48, 157);
        $page->text('E-mail : ' . (string) ($customer['email'] ?? 'Non renseigné'), 48, 175);
        $page->line(48, 192, 548, 192, 1);
        $page->setFont($font, 10)->setColor('#0f172a');
        $page->text('Article', 48, 212);
        $page->text('Quantité', 350, 212);
        $page->text('Montant', 450, 212);
        $y = 236;
        foreach ($order->lines as $line) {
            if ($y > 760) {
                $page = $document->addPage();
                $page->setFont($font, 10)->setColor('#0f172a');
                $y = 55;
            }
            $page->text(Utf8::truncate((string) $line['label'], 60), 48, $y);
            $page->text((string) $line['quantity'] . ' ' . (string) $line['unit'], 350, $y);
            $page->text((new Money((int) $line['line_amount_minor'], $order->total->currency))->format(), 450, $y);
            $y += 20;
        }
        if ($y > 700) {
            $page = $document->addPage();
            $page->setFont($font, 10)->setColor('#0f172a');
            $y = 55;
        }
        $page->line(48, $y + 4, 548, $y + 4, 1);
        $page->setFont($font, 10);
        $page->text('Sous-total : ' . (new Money($order->subtotal->minorAmount, $order->subtotal->currency))->format(), 350, $y + 24);
        $page->text('Taxes : ' . (new Money($order->tax->minorAmount, $order->tax->currency))->format(), 350, $y + 42);
        $page->setFont($font, 13)->text(
            'Total : ' . (new Money($order->total->minorAmount, $order->total->currency))->format(),
            350,
            $y + 64,
        );

        $matrix = QrCode::for('SALES-ORDER:' . ($order->publicReference ?? $order->id))->build();
        $module = 1.7;
        $originX = 466.0;
        $originY = 42.0;
        $quietZone = 4;
        $page->setColor('#ffffff')->rect(
            $originX,
            $originY,
            ($matrix->size + $quietZone * 2) * $module,
            ($matrix->size + $quietZone * 2) * $module,
            'fill',
        );
        $page->setColor('#0f172a');
        for ($row = 0; $row < $matrix->size; ++$row) {
            for ($column = 0; $column < $matrix->size; ++$column) {
                if ($matrix->isDark($column, $row)) {
                    $page->rect(
                        $originX + ($column + $quietZone) * $module,
                        $originY + ($row + $quietZone) * $module,
                        $module,
                        $module,
                        'fill',
                    );
                }
            }
        }

        return $document->render();
    }

    private static function statusLabel(string $status): string
    {
        return [
            SalesOrderState::PENDING => 'En attente',
            SalesOrderState::CONFIRMED => 'Confirmée',
            SalesOrderState::PROCESSING => 'En traitement',
            SalesOrderState::READY => 'Prête',
            SalesOrderState::COMPLETED => 'Terminée',
            SalesOrderState::CANCELLED => 'Annulée',
        ][$status] ?? $status;
    }
}
