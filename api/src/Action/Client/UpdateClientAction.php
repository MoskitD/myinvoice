<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateClientAction
{
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!SupplierGuard::owns($request, $this->repo->find($id))) {
            return Json::error($response, 'not_found', 'Klient nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $errors = Validation::client($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        try {
            $backfilled = $this->repo->update($id, $body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('client.updated', $user['id'] ?? null, 'client', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        // *_category_backfilled = počet faktur, do kterých byla doplněna nově nastavená
        // výchozí kategorie nákladu / tržby (frontend ukáže toast).
        $client = $this->repo->find($id) ?? [];
        $client['expense_category_backfilled'] = $backfilled['expense'];
        $client['revenue_category_backfilled'] = $backfilled['revenue'];
        return Json::ok($response, $client);
    }
}
