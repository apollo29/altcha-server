<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\ChallengeOptions;

$altchaHMACKey = $_ENV['ALTCHA_HMAC_KEY'];

$app->group('/', function (RouteCollectorProxy $group) use ($altchaHMACKey) {
    $group->get('altcha', function (Request $request, Response $response) use ($altchaHMACKey) {
        $challengeOptions = new ChallengeOptions([
            'hmacKey' => $altchaHMACKey,
            'maxNumber' => 50000,
        ]);

        try {
            $challenge = Altcha::createChallenge($challengeOptions);
            $response->getBody()->write(json_encode($challenge));
        } catch (Exception $e) {
            $response->getBody()->write('Failed to create challenge: ' . $e->getMessage());
            return $response->withStatus(500);
        }

        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->post('submit', function (Request $request, Response $response) use ($altchaHMACKey) {
        $parsedBody = $request->getParsedBody();
        $formData = $parsedBody ?? [];
        $payload = $parsedBody['altcha'] ?? '';

        if (!$formData) {
            $response->getBody()->write('Altcha payload missing');
            return $response->withStatus(400);
        }

        try {
            $decodedPayload = base64_decode($payload);
            $payload = json_decode($decodedPayload, true);

            $verified = Altcha::verifySolution($payload, $altchaHMACKey, true);
            if (!$verified) {
                $response->getBody()->write('Invalid Altcha payload');
                return $response->withStatus(400);
            }

            $response->getBody()->write(json_encode(['success' => true, 'data' => $formData]));
        } catch (Exception $e) {
            $response->getBody()->write('Failed to process Altcha payload: ' . $e->getMessage());
            return $response->withStatus(400);
        }

        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->post('submit_spam_filter', function (Request $request, Response $response) use ($altchaHMACKey) {
        $parsedBody = $request->getParsedBody();
        $formData = $parsedBody ?? [];
        $payload = $parsedBody['altcha'] ?? '';

        if (!$payload) {
            $response->getBody()->write('Altcha payload missing');
            return $response->withStatus(400);
        }

        try {
            list($verified, $verificationData) = Altcha::verifyServerSignature($payload, $altchaHMACKey);
            if (!$verified || !$verificationData || $verificationData->expire <= time()) {
                $response->getBody()->write('Invalid Altcha payload');
                return $response->withStatus(400);
            }

            if ($verificationData->classification == 'BAD') {
                $response->getBody()->write('Classified as spam');
                return $response->withStatus(400);
            }

            if ($verificationData->fieldsHash) {
                $verifiedFields = Altcha::verifyFieldsHash($formData, $verificationData->fields, $verificationData->fieldsHash, 'SHA-256');
                if (!$verifiedFields) {
                    $response->getBody()->write('Invalid fields hash');
                    return $response->withStatus(400);
                }
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $formData,
                'verificationData' => $verificationData,
            ]));
        } catch (Exception $e) {
            $response->getBody()->write('Failed to process Altcha payload: ' . $e->getMessage());
            return $response->withStatus(400);
        }

        return $response->withHeader('Content-Type', 'application/json');
    });
});
