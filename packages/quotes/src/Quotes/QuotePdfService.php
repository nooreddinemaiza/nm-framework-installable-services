<?php

declare(strict_types=1);

namespace NM\Services\Quotes\Quotes;

use NM\Services\Quotes\Models\QuoteRecord;
use Core\Support\PDF\Document;
use Core\Support\PDF\Font\StandardFont;
use Core\Support\PDF\Text\Utf8;

final class QuotePdfService
{
    public function __construct(private readonly QuoteRepository $repository)
    {
    }

    public function render(QuoteRecord $quote): string
    {
        $document = (new Document())->setMetadata([
            'title' => 'Devis ' . $quote->getAttribute('reference'),
            'subject' => 'Devis commercial',
            'creator' => 'Service Devis',
        ]);
        $font = StandardFont::helvetica();
        $page = $document->addPage();
        $page->setFont($font, 20)->setColor('#0f766e')
            ->text('Devis ' . (string) $quote->getAttribute('reference'), 48, 62);
        $page->setFont($font, 11)->setColor('#1e293b');
        $page->text('Demandeur : ' . (string) $quote->getAttribute('customer_name'), 48, 95);
        $page->text('E-mail : ' . (string) $quote->getAttribute('customer_email'), 48, 113);
        $page->text('Créé le : ' . (string) $quote->getAttribute('created_at'), 48, 131);
        $page->text('Expire le : ' . (string) ($quote->getAttribute('expires_at') ?? 'Sans expiration'), 48, 149);
        $page->line(48, 166, 548, 166, 1);
        $page->text('Article', 48, 188);
        $page->text('Qté', 330, 188);
        $page->text('Prix', 390, 188);
        $page->text('Total', 470, 188);

        $y = 212;
        foreach ($this->repository->items((int) $quote->getAttribute('id')) as $item) {
            if ($y > 760) {
                $page = $document->addPage();
                $page->setFont($font, 10)->setColor('#1e293b');
                $y = 55;
            }
            $page->text(Utf8::truncate((string) $item->getAttribute('item_name'), 45), 48, $y);
            $page->text((string) $item->getAttribute('quantity'), 330, $y);
            $page->text((string) $item->getAttribute('unit_price'), 390, $y);
            $page->text((string) $item->getAttribute('line_total'), 470, $y);
            $y += 20;
        }
        $currency = (string) $quote->getAttribute('currency');
        $page->line(48, $y + 4, 548, $y + 4, 1);
        $page->setFont($font, 12);
        $page->text('Sous-total : ' . $quote->getAttribute('subtotal') . ' ' . $currency, 350, $y + 28);
        $page->text('Remises : ' . $quote->getAttribute('discount_total') . ' ' . $currency, 350, $y + 46);
        $page->text('Taxes : ' . $quote->getAttribute('tax_total') . ' ' . $currency, 350, $y + 64);
        $page->setFont($font, 14)
            ->text('Total : ' . $quote->getAttribute('total') . ' ' . $currency, 350, $y + 86);

        return $document->render();
    }
}
