<?php

declare(strict_types=1);

namespace MyInvoice\Action\Codebook;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tax\TaxConstants;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Číselník ročních daňových konstant (GLOBÁLNÍ, admin-only):
 *   GET    /api/codebooks/tax-constants        — seznam roků (efektivní data + is_override)
 *   PUT    /api/codebooks/tax-constants/{year}  — uložit override pro rok
 *   DELETE /api/codebooks/tax-constants/{year}  — reset na default (smazat override)
 *
 * List je čitelný i pro ne-admina (read-only zobrazení), zápis jen admin.
 * Hodnoty jsou národní (ne per-supplier), proto bez supplier scope.
 */
final class TaxConstantsAction
{
    public function __construct(
        private readonly TaxConstantsRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** GET /api/codebooks/tax-constants */
    public function list(Request $request, Response $response): Response
    {
        return Json::ok($response, ['years' => $this->repo->listEffective()]);
    }

    /** PUT /api/codebooks/tax-constants/{year} */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin smí měnit daňové konstanty.', 403);
        }
        $year = (int) ($args['year'] ?? 0);
        if ($year < 2018 || $year > 2100) {
            return Json::error($response, 'invalid_year', 'Neplatný rok.', 422);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;
        $err = $this->validate($data);
        if ($err !== null) {
            return Json::error($response, 'validation_failed', $err, 422);
        }

        $this->repo->upsert($year, $data);
        $this->audit($request, 'tax_constants.updated', $year);
        return Json::ok($response, [
            'year'        => $year,
            'is_override' => true,
            'data'        => $this->repo->forYear($year),
        ]);
    }

    /** DELETE /api/codebooks/tax-constants/{year} — reset na default */
    public function reset(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin smí měnit daňové konstanty.', 403);
        }
        $year = (int) ($args['year'] ?? 0);
        $this->repo->reset($year);
        $this->audit($request, 'tax_constants.reset', $year);
        return Json::ok($response, [
            'year'        => $year,
            'is_override' => false,
            'data'        => TaxConstants::forYear($year),
        ]);
    }

    /** Minimální validace — povinné skalární klíče + struktura vnořených. */
    private function validate(array $d): ?string
    {
        $scalars = [
            'credit_taxpayer', 'credit_spouse', 'tax_rate_low', 'tax_rate_high', 'tax_high_threshold',
            'social_rate', 'health_rate', 'social_assessment_pct', 'health_assessment_pct',
            'social_min_base_main', 'social_min_base_secondary', 'health_min_base',
            'mortgage_cap', 'pension_cap', 'vat_limit_low', 'vat_limit_high',
            'vat_rate_standard', 'vat_rate_reduced', 'kh_item_threshold',
        ];
        foreach ($scalars as $k) {
            if (!isset($d[$k]) || !is_numeric($d[$k])) {
                return "Chybí nebo není číslo: {$k}";
            }
        }
        foreach (['pausal_annual', 'band_ceilings', 'expense_caps'] as $k) {
            if (!isset($d[$k]) || !is_array($d[$k])) {
                return "{$k} musí být objekt.";
            }
        }
        if (!isset($d['child_credits']) || !is_array($d['child_credits']) || $d['child_credits'] === []) {
            return 'child_credits musí být neprázdné pole.';
        }
        return null;
    }

    private function isAdmin(Request $request): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return ($user['role'] ?? '') === 'admin';
    }

    private function audit(Request $request, string $action, int $year): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $user['id'] ?? null, 'tax_constants', $year, [], $ip, $request->getHeaderLine('User-Agent'));
    }
}
