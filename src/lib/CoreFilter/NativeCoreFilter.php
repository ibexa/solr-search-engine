<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

namespace Ibexa\Solr\CoreFilter;

use Ibexa\Contracts\Core\Repository\Values\Content\Query;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion\CustomField;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion\LogicalAnd;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion\LogicalNot;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion\LogicalOr;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion\Operator;
use Ibexa\Solr\CoreFilter;
use Ibexa\Solr\Gateway\EndpointResolver;

/**
 * Native core filter handles:.
 *
 * - search type (Content and Location)
 * - prioritized languages fallback
 * - always available language fallback
 * - main language search
 * - optionally ignore language filter, search in all translations
 */
class NativeCoreFilter extends CoreFilter
{
    /**
     * Name of the Solr backend field holding document type identifier
     * ('content' or 'location').
     *
     * @var string
     */
    public const FIELD_DOCUMENT_TYPE = 'document_type_id';

    /**
     * Name of the Solr backend field holding list of all translation's Content
     * language codes.
     *
     * @var string
     */
    public const FIELD_LANGUAGES = 'content_language_codes_ms';

    /**
     * Name of the Solr backend field holding language code of the indexed
     * translation.
     *
     * @var string
     */
    public const FIELD_LANGUAGE = 'meta_indexed_language_code_s';

    /**
     * Name of the Solr backend field indicating if the indexed translation
     * is in the main language.
     *
     * @var string
     */
    public const FIELD_IS_MAIN_LANGUAGE = 'meta_indexed_is_main_translation_b';

    /**
     * Name of the Solr backend field indicating if the indexed translation
     * is always available.
     *
     * @var string
     */
    public const FIELD_IS_ALWAYS_AVAILABLE = 'meta_indexed_is_main_translation_and_always_available_b';

    /**
     * Name of the Solr backend field indicating if the indexed document is
     * located in the main translations index.
     *
     * @var string
     */
    public const FIELD_IS_MAIN_LANGUAGES_INDEX = 'meta_indexed_main_translation_b';

    /**
     * Indicates presence of main languages index.
     *
     * @var bool
     */
    private $hasMainLanguagesEndpoint;

    public function __construct(EndpointResolver $endpointResolver)
    {
        $this->hasMainLanguagesEndpoint = (
            $endpointResolver->getMainLanguagesEndpoint() !== null
        );
    }

    public function apply(Query $query, array $languageSettings, $documentTypeIdentifier)
    {
        $languages = (
            empty($languageSettings['languages']) ?
                [] :
                $languageSettings['languages']
        );
        $useAlwaysAvailable = (
            !isset($languageSettings['useAlwaysAvailable']) ||
            $languageSettings['useAlwaysAvailable'] === true
        );

        $excludeTranslationsFromAlwaysAvailable =
            $languageSettings['excludeTranslationsFromAlwaysAvailable'] ?? true;

        $excludeCoreCriterion =
            $languageSettings['excludeCoreCriterion'] ?? false;

        $criteria = [
            new CustomField(self::FIELD_DOCUMENT_TYPE, Operator::EQ, $documentTypeIdentifier),
        ];

        if (!$excludeCoreCriterion) {
            $criteria[] = $this->getCoreCriterion(
                $languages,
                $useAlwaysAvailable,
                $excludeTranslationsFromAlwaysAvailable
            );
        }

        if ($query->filter !== null) {
            $criteria[] = $query->filter;
        }

        $query->filter = new LogicalAnd($criteria);
    }

    /**
     * Returns a filtering condition for the given language settings.
     *
     * The condition ensures the same Content will be matched only once across all
     * targeted translation endpoints.
     *
     * @param string[] $languageCodes
     */
    private function getCoreCriterion(
        array $languageCodes,
        bool $useAlwaysAvailable,
        bool $excludeTranslationsFromAlwaysAvailable = true
    ): Criterion {
        // Handle languages if given
        if (!empty($languageCodes)) {
            // Get condition for prioritized languages fallback
            $filter = $this->getLanguageFilter($languageCodes);

            // Handle always available fallback if used
            if ($useAlwaysAvailable) {
                // Combine conditions with OR
                $filter = new LogicalOr(
                    [
                        $filter,
                        $this->getAlwaysAvailableFilter(
                            $languageCodes,
                            $excludeTranslationsFromAlwaysAvailable
                        ),
                    ]
                );
            }

            // Return languages condition
            return $filter;
        }

        // Otherwise search only main languages
        return new CustomField(self::FIELD_IS_MAIN_LANGUAGE, Operator::EQ, true);
    }

    /**
     * Returns criteria for prioritized languages fallback.
     *
     * @param string[] $languageCodes
     *
     * @return \Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion
     */
    private function getLanguageFilter(array $languageCodes)
    {
        $languageFilters = [];

        foreach ($languageCodes as $languageCode) {
            // Include language
            $condition = new CustomField(self::FIELD_LANGUAGE, Operator::EQ, $languageCode);
            // Get list of excluded languages
            $excluded = $this->getExcludedLanguageCodes($languageCodes, $languageCode);

            // Combine if list is not empty
            if (!empty($excluded)) {
                $condition = new LogicalAnd(
                    [
                        $condition,
                        new LogicalNot(
                            new CustomField(self::FIELD_LANGUAGES, Operator::IN, $excluded)
                        ),
                    ]
                );
            }

            $languageFilters[] = $condition;
        }

        // Combine language fallback conditions with OR
        if (\count($languageFilters) > 1) {
            $languageFilters = [new LogicalOr($languageFilters)];
        }

        // Exclude main languages index if used
        if ($this->hasMainLanguagesEndpoint) {
            $languageFilters[] = new LogicalNot(
                new CustomField(self::FIELD_IS_MAIN_LANGUAGES_INDEX, Operator::EQ, true)
            );
        }

        // Combine conditions
        if (\count($languageFilters) > 1) {
            return new LogicalAnd($languageFilters);
        }

        return reset($languageFilters);
    }

    /**
     * Returns criteria for always available translation fallback.
     *
     * @param string[] $languageCodes
     */
    private function getAlwaysAvailableFilter(
        array $languageCodes,
        bool $excludeTranslationsFromAlwaysAvailable = true
    ): Criterion {
        $excludeOnField = $excludeTranslationsFromAlwaysAvailable
            // Exclude all translations by given languages
            ? self::FIELD_LANGUAGES
            // Exclude only main translation by given languages
            : self::FIELD_LANGUAGE
        ;

        $conditions = [
            // Include always available main language translations
            new CustomField(
                self::FIELD_IS_ALWAYS_AVAILABLE,
                Operator::EQ,
                true
            ),

            new LogicalNot(
                new CustomField($excludeOnField, Operator::IN, $languageCodes)
            ),
        ];

        // Include only from main languages index if used
        if ($this->hasMainLanguagesEndpoint) {
            $conditions[] = new CustomField(
                self::FIELD_IS_MAIN_LANGUAGES_INDEX,
                Operator::EQ,
                true
            );
        }

        // Combine conditions
        return new LogicalAnd($conditions);
    }

    /**
     * Returns a list of language codes to be excluded when matching translation in given
     * $selectedLanguageCode.
     *
     * If $selectedLanguageCode is omitted, all languages will be returned.
     *
     * @param string[] $languageCodes
     * @param string|null $selectedLanguageCode
     *
     * @return string[]
     */
    private function getExcludedLanguageCodes(array $languageCodes, $selectedLanguageCode = null)
    {
        $excludedLanguageCodes = [];

        foreach ($languageCodes as $languageCode) {
            if ($selectedLanguageCode !== null && $languageCode === $selectedLanguageCode) {
                break;
            }

            $excludedLanguageCodes[] = $languageCode;
        }

        return $excludedLanguageCodes;
    }
}
