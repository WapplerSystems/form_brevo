<?php

declare(strict_types=1);

namespace WapplerSystems\FormBrevo\Finishers;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use TYPO3\CMS\Form\Domain\Finishers\Exception\FinisherException;
use TYPO3\CMS\Form\Domain\Model\FormElements\FormElementInterface;
use TYPO3\CMS\Form\Domain\Runtime\FormRuntime;
use WapplerSystems\FormBrevo\Brevo\ApiException;
use WapplerSystems\FormBrevo\Brevo\Client;

/**
 * Hands a form submission to Brevo: creates or updates the contact, puts it
 * on the configured lists, or, with doubleOptIn, sends Brevo's confirmation
 * mail first.
 *
 * Replaces the HTML/iframe embeds of Brevo forms. The browser only talks to
 * TYPO3, so no connection to Brevo has to be consented to, and the form uses
 * the site's own markup.
 *
 * Options:
 *   emailField      form element holding the address (default "email")
 *   listIds         Brevo list ids, comma-separated or as YAML list
 *   attributes      map Brevo attribute => value, e.g. VORNAME: '{firstName}';
 *                   values may be literals ("SOURCE: cpid-002"), empty values
 *                   are left out so they never blank an existing attribute
 *   updateExisting  update a contact that already exists (default true)
 *   doubleOptIn     send Brevo's DOI mail instead of adding directly
 *   templateId      Brevo DOI template id (required with doubleOptIn)
 *   redirectionUrl  page Brevo sends the visitor to after confirming
 *                   (required with doubleOptIn)
 */
class BrevoSubscribeFinisher extends AbstractFinisher
{
    private const LLL = 'LLL:EXT:form_brevo/Resources/Private/Language/locallang.xlf:';

    protected $defaultOptions = [
        'emailField' => 'email',
        'listIds' => '',
        'attributes' => [],
        'updateExisting' => true,
        'doubleOptIn' => false,
        'templateId' => 0,
        'redirectionUrl' => '',
    ];

    public function __construct(
        private readonly Client $client,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @throws FinisherException
     */
    protected function executeInternal(): ?string
    {
        $formRuntime = $this->finisherContext->getFormRuntime();
        $identifier = $formRuntime->getFormDefinition()->getIdentifier();

        $emailField = (string)$this->parseOption('emailField');
        $email = trim((string)($formRuntime[$emailField] ?? ''));
        if ($email === '') {
            $this->logger?->warning('form_brevo: no address in field "{field}" of form "{form}", nothing sent', [
                'field' => $emailField,
                'form' => $identifier,
            ]);
            return null;
        }

        $listIds = self::normalizeListIds($this->parseOption('listIds'));
        $attributes = self::normalizeAttributes(
            (array)($this->parseOption('attributes') ?? []),
            $this->checkboxAttributes($formRuntime),
        );
        $doubleOptIn = (bool)$this->parseOption('doubleOptIn');

        try {
            if ($doubleOptIn) {
                $templateId = (int)$this->parseOption('templateId');
                $redirectionUrl = trim((string)$this->parseOption('redirectionUrl'));
                if ($templateId <= 0 || $redirectionUrl === '' || $listIds === []) {
                    throw new ApiException('Double opt-in needs templateId, redirectionUrl and listIds.', 0, '', 1760620804);
                }
                $this->client->requestDoubleOptIn($email, $attributes, $listIds, $templateId, $redirectionUrl);
            } else {
                $this->client->createOrUpdateContact($email, $attributes, $listIds, (bool)$this->parseOption('updateExisting'));
            }
        } catch (ApiException $e) {
            $context = [
                'form' => $identifier,
                'email' => self::mask($email),
                'status' => $e->status,
                'code' => $e->errorCode(),
                'error' => $e->errorMessage() ?: $e->getMessage(),
            ];

            if ($e->isRejectedEmail()) {
                $this->logger?->warning('form_brevo: Brevo rejected the address', $context);
                $this->finisherContext->cancel();
                return $this->renderRejection($formRuntime, $this->translate('finisher.subscribe.emailRejected'));
            }

            // Anything else is our side (key, list, template, outage). The
            // visitor must not see a success message for a request that never
            // arrived, so this ends in the form's error view.
            $this->logger?->error('form_brevo: submission not delivered to Brevo', $context);
            $this->options['errorMessage'] = $this->translate('finisher.subscribe.error');
            throw new FinisherException('Brevo submission failed: ' . $e->getMessage(), 1760620805, $e);
        }

        $this->logger?->info('form_brevo: contact handed to Brevo', [
            'form' => $identifier,
            'email' => self::mask($email),
            'lists' => $listIds,
            'doubleOptIn' => $doubleOptIn,
        ]);
        return null;
    }

    /**
     * @return list<int>
     */
    public static function normalizeListIds(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string)$value);
        $ids = [];
        foreach ($items as $item) {
            $id = (int)trim((string)$item);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Drops empty values, joins multi-value fields and sends a checked
     * checkbox as true, which is what Brevo's boolean attributes expect.
     *
     * @param array<array-key, mixed> $attributes resolved option values
     * @param list<string> $booleanAttributes attribute names fed by a checkbox
     * @return array<string, scalar>
     */
    public static function normalizeAttributes(array $attributes, array $booleanAttributes = []): array
    {
        $result = [];
        foreach ($attributes as $name => $value) {
            $name = strtoupper(trim((string)$name));
            if ($name === '') {
                continue;
            }
            if (in_array($name, $booleanAttributes, true)) {
                // Unchecked counts as empty: an update must not turn a
                // consent Brevo already holds back to false.
                if (!in_array($value, [null, '', '0', 0, false, []], true)) {
                    $result[$name] = true;
                }
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', $value), static fn(string $v): bool => $v !== ''));
            }
            if ($value === null || is_object($value)) {
                continue;
            }
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '') {
                continue;
            }
            $result[$name] = $value;
        }
        return $result;
    }

    /**
     * Attribute names whose configured value is exactly "{field}" with a
     * Checkbox element behind it.
     *
     * @return list<string>
     */
    private function checkboxAttributes(FormRuntime $formRuntime): array
    {
        $names = [];
        foreach ((array)($this->options['attributes'] ?? []) as $name => $raw) {
            if (!is_string($raw) || !preg_match('/^{([^}]+)}$/', $raw, $m)) {
                continue;
            }
            $element = $formRuntime->getFormDefinition()->getElementByIdentifier($m[1]);
            if ($element instanceof FormElementInterface && $element->getType() === 'Checkbox') {
                $names[] = strtoupper(trim((string)$name));
            }
        }
        return $names;
    }

    private function renderRejection(FormRuntime $formRuntime, string $message): string
    {
        return sprintf(
            '<div class="alert alert-warning" id="%s" data-formstate="rejected">%s</div>',
            htmlspecialchars($formRuntime->getFormDefinition()->getIdentifier(), ENT_QUOTES, 'UTF-8'),
            nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'))
        );
    }

    private function translate(string $key): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $language = $request instanceof ServerRequestInterface ? $request->getAttribute('language') : null;
        $languageService = $language instanceof SiteLanguage
            ? $this->languageServiceFactory->createFromSiteLanguage($language)
            : $this->languageServiceFactory->create('default');
        return $languageService->sL(self::LLL . $key);
    }

    /**
     * Keeps the log useful without holding plain addresses.
     */
    private static function mask(string $address): string
    {
        $at = strrpos($address, '@');
        if ($at === false || $at === 0) {
            return substr($address, 0, 2) . '***';
        }
        return substr($address, 0, 2) . '***' . substr($address, $at);
    }
}
