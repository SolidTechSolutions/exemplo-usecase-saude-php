<?php
declare(strict_types=1);

/**
 * [EN]    Health Documents (Receita Médica e correlatos) use case — single sign+rubric endpoint, KMS custody.
 *         Setup: composer install && cp .env.example .env  (edit .env)
 *         Run:   php -S 0.0.0.0:8097 -t public
 * [PT-BR] Caso de uso Documentos de Saúde — endpoint único de assinatura+rubrica, custódia KMS.
 *         Configurar: composer install && cp .env.example .env  (editar .env)
 *         Executar:   php -S 0.0.0.0:8097 -t public
 */

require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use SolidSign\SaudeSigningService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$app = AppFactory::create();
$service = new SaudeSigningService();

$app->post('/api/saude/sign-documento', function (Request $request, Response $response) use ($service) {
    $params = (array) $request->getParsedBody();
    $uploadedFiles = $request->getUploadedFiles();
    $document = $uploadedFiles['document'] ?? null;
    $kmsCode = $params['kmsCode'] ?? null;
    $documentType = $params['documentType'] ?? null;

    if ($document === null || $document->getError() !== UPLOAD_ERR_OK || $kmsCode === null || $documentType === null) {
        $response->getBody()->write(json_encode(['error' => 'document, kmsCode and documentType are required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $signed = $service->sign(
        (string) $document->getStream(), $document->getClientFilename() ?? 'document.pdf', $kmsCode, $documentType,
        $params['professionalName'] ?? '', $params['professionalRegistro'] ?? '',
        $params['professionalUf'] ?? '', $params['professionalEspecialidade'] ?? ''
    );
    if ($signed === null) {
        $response->getBody()->write(json_encode(['error' => 'Signing failed. Check logs.']));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    $response->getBody()->write($signed);
    return $response
        ->withHeader('Content-Type', 'application/pdf')
        ->withHeader('Content-Disposition', "attachment; filename=\"{$documentType}_signed.pdf\"");
});

$app->run();
