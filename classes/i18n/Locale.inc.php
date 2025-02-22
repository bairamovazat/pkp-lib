<?php
declare(strict_types = 1);

/**
 * @defgroup i18n I18N
 * Implements localization concerns such as locale files, time zones, and country lists.
 */

/**
 * @file classes/i18n/Locale.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Locale
 * @ingroup i18n
 *
 * @brief Provides methods for loading locale data and translating strings identified by unique keys
 */

namespace PKP\i18n;

use Closure;
use DateInterval;
use DirectoryIterator;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use PKP\config\Config;
use PKP\core\PKPRequest;
use PKP\facades\Repo;
use PKP\i18n\interfaces\LocaleInterface;
use PKP\i18n\translation\LocaleBundle;
use PKP\plugins\HookRegistry;
use PKP\plugins\PluginRegistry;
use PKP\session\SessionManager;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use Sokil\IsoCodes\IsoCodesFactory;
use Sokil\IsoCodes\Database\Countries;
use Sokil\IsoCodes\Database\Currencies;
use Sokil\IsoCodes\Database\LanguagesInterface;
use Sokil\IsoCodes\Database\Scripts;
use SplFileInfo;

class Locale implements LocaleInterface
{
    /** Max lifetime for the locale metadata cache, the cache is built by scanning the provided paths */
    protected const MAX_CACHE_LIFETIME = '1 hour';
    
    /**
     * @var callable Formatter for missing locale keys
     * Receives the locale key and must return a string
     */
    protected ?Closure $missingKeyHandler = null;

    /** Current locale cache */
    protected ?string $locale = null;

    /** @var int[] Folders where locales can be found, where key = path and value = loading priority */
    protected array $paths = [];

    /** @var callable[] Custom locale loaders */
    protected array $loaders = [];

    /** Keeps the request */
    protected ?PKPRequest $request = null;

    /** @var LocaleMetadata[]|null Discovered locales cache */
    protected ?array $locales = null;

    /** Primary locale cache */
    protected ?string $primaryLocale = null;

    /** @var string[]|null Supported form locales cache, where key = locale and value = name */
    protected ?array $supportedFormLocales = null;

    /** @var string[]|null Supported locales cache, where key = locale and value = name */
    protected ?array $supportedLocales = null;

    /** @var LocaleBundle[] Keeps a cache for the locale bundles */
    protected array $localeBundles = [];

    /** @var string[][][]|null Discovered locale files, keyed first by base path and then by locale */
    protected array $localeFiles = [];

    /** Keeps cached data related only to the current locale */
    protected array $cache = [];

    /**
     * @copy \Illuminate\Contracts\Translation\Translator::get()
     */
    public function get($key, array $params = [], $locale = null): string
    {
        return $this->translate($key, null, $params, $locale);
    }

    /**
     * @copy \Illuminate\Contracts\Translation\Translator::choice()
     */
    public function choice($key, $number, array $params = [], $locale = null): string
    {
        return $this->translate($key, $number, $params, $locale);
    }

    /**
     * @copy \Illuminate\Contracts\Translation\Translator::getLocale()
     */
    public function getLocale(): string
    {
        if (isset($this->locale)) {
            return $this->locale;
        }
        $request = $this->_getRequest();
        $locale = $request->getUserVar('setLocale')
            ?: (SessionManager::hasSession() ? SessionManager::getManager()->getUserSession()->getSessionVar('currentLocale') : null)
            ?: $request->getCookieVar('currentLocale');
        $this->setLocale($locale);
        return $this->locale;
    }

    /**
     * @copy \Illuminate\Contracts\Translation\Translator::setLocale()
     */
    public function setLocale($locale): void
    {
        if (!$this->isLocaleValid($locale) || !$this->isSupported($locale)) {
            if ($locale) {
                error_log((string) new InvalidArgumentException("Invalid/unsupported locale \"${locale}\", default locale restored"));
            }
            $locale = $this->getPrimaryLocale();
        }

        $this->locale = $locale;
        setlocale(LC_ALL, "${locale}.utf-8", $locale);
        putenv("LC_ALL=${locale}");
    }

    /**
     * @copy LocaleInterface::getPrimaryLocale()
     */
    public function getPrimaryLocale(): string
    {
        if (isset($this->primaryLocale)) {
            return $this->primaryLocale;
        }
        $request = $this->_getRequest();
        $locale = null;
        if (SessionManager::isDisabled()) {
            $locale = $this->getDefaultLocale();
        } elseif ($context = $request->getContext()) {
            $locale = $context->getPrimaryLocale();
        } elseif ($site = $request->getSite()) {
            $locale = $site->getPrimaryLocale();
        }
        return $this->primaryLocale = $this->isLocaleValid($locale)
            ? $locale
            : $this->getDefaultLocale();
    }

    /**
     * @copy LocaleInterface::registerPath()
     */
    public function registerPath(string $path, int $priority = 0): void
    {
        $path = new SplFileInfo($path);
        if (!$path->isDir()) {
            throw new InvalidArgumentException("${path} isn't a valid folder");
        }

        // Invalidate the loaded bundles cache
        $realPath = $path->getRealPath();
        if (($this->paths[$realPath] ?? null) !== $priority) {
            $this->paths[$realPath] = $priority;
            $this->localeBundles = [];
            $this->locales = null;
        }
    }

    /**
     * @copy LocaleInterface::registerLoader()
     */
    public function registerLoader(callable $fileLoader, int $priority = 0): void
    {
        // Invalidate the loaded bundles cache
        if (array_search($fileLoader, $this->loaders[$priority] ?? [], true) === false) {
            $this->loaders[$priority][] = $fileLoader;
            $this->localeBundles = [];
            ksort($this->loaders, SORT_NUMERIC);
        }
    }

    /**
     * @copy LocaleInterface::isLocaleValid()
     */
    public function isLocaleValid(?string $locale): bool
    {
        return !empty($locale) && preg_match(LocaleInterface::LOCALE_EXPRESSION, $locale);
    }

    /**
     * @copy LocaleInterface::getMetadata()
     */
    public function getMetadata(string $locale): ?LocaleMetadata
    {
        return $this->getLocales()[$locale] ?? null;
    }

    /**
     * @copy LocaleInterface::getLocales()
     */
    public function getLocales(): array
    {
        $key = __METHOD__ . static::MAX_CACHE_LIFETIME . array_reduce(array_keys($this->paths), fn(string $hash, string $path): string => sha1($hash . $path), '');
        $expiration = DateInterval::createFromDateString(static::MAX_CACHE_LIFETIME);
        return $this->locales ??= Cache::remember($key, $expiration, function () {
            $locales = [];
            foreach (array_keys($this->paths) as $folder) {
                foreach (new DirectoryIterator($folder) as $cursor) {
                    if ($cursor->isDir() && $this->isLocaleValid($cursor->getBasename())) {
                        $locales[$cursor->getBasename()] ??= LocaleMetadata::create($cursor->getBasename());
                    }
                }
            }
            ksort($locales);
            return $locales;
        });
    }

    /**
     * @copy LocaleInterface::installLocale()
     */
    public function installLocale(string $locale): void
    {
        Repo::emailTemplate()->dao->installEmailTemplateLocaleData(Repo::emailTemplate()->dao->getMainEmailTemplatesFilename(), [$locale]);

        // Load all plugins so they can add locale data if needed
        $categories = PluginRegistry::getCategories();
        foreach ($categories as $category) {
            PluginRegistry::loadCategory($category);
        }
        HookRegistry::call('Locale::installLocale', [&$locale]);
    }

    /**
     * @copy LocaleInterface::uninstallLocale()
     */
    public function uninstallLocale(string $locale): void
    {
        // Delete locale-specific data
        Repo::emailTemplate()->dao->deleteEmailTemplatesByLocale($locale);
        Repo::emailTemplate()->dao->deleteDefaultEmailTemplatesByLocale($locale);
    }

    /**
     * Retrieves whether the given locale is supported
     */
    public function isSupported(string $locale): bool
    {
        static $locales;
        $locales ??= SessionManager::isDisabled()
            ? array_keys($this->getLocales())
            : (($context = $this->_getRequest()->getContext()) ? $context->getSupportedLocales() : $this->_getRequest()->getSite()->getSupportedLocales());
        return in_array($locale, $locales);
    }

    /**
     * @copy LocaleInterface::getSupportedFormLocales()
     */
    public function getSupportedFormLocales(): array
    {
        return $this->supportedFormLocales ??= (fn(): array => SessionManager::isDisabled()
            ? array_map(fn(LocaleMetadata $locale) => $locale->locale, $this->getLocales())
            : (($context = $this->_getRequest()->getContext()) ? $context->getSupportedFormLocaleNames() : $this->_getRequest()->getSite()->getSupportedLocaleNames())
        )();
    }

    /**
     * @copy LocaleInterface::getSupportedLocales()
     */
    public function getSupportedLocales(): array
    {
        return $this->supportedLocales ??= (fn(): array => SessionManager::isDisabled()
            ? array_map(fn(LocaleMetadata $locale) => $locale->locale, $this->getLocales())
            : ($this->_getRequest()->getContext() ?? $this->_getRequest()->getSite())->getSupportedLocaleNames()
        )();
    }

    /**
     * @copy LocaleInterface::setMissingKeyHandler()
     */
    public function setMissingKeyHandler(?callable $handler): void
    {
        $this->missingKeyHandler = $handler;
    }

    /**
     * @copy LocaleInterface::getMissingKeyHandler()
     */
    public function getMissingKeyHandler(): ?callable
    {
        return $this->missingKeyHandler;
    }

    /**
     * @copy LocaleInterface::getBundle()
     */
    public function getBundle(?string $locale = null, bool $useCache = true): LocaleBundle
    {
        $locale ??= $this->getLocale();
        $getter = function () use ($locale): LocaleBundle {
            $bundle = [];
            foreach ($this->paths as $folder => $priority) {
                $bundle += $this->_getLocaleFiles($folder, $locale, $priority);
            }
            foreach ($this->loaders as $loader) {
                $loader($locale, $bundle);
            }
            return new LocaleBundle($locale, $bundle);
        };
        return $useCache ? $this->localeBundles[$locale] ??= $getter() : $getter();
    }

    /**
     * @copy LocaleInterface::getDefaultLocale()
     */
    public function getDefaultLocale(): string
    {
        return Config::getVar('i18n', 'locale');
    }

    /**
     * @copy LocaleInterface::getCountries()
     */
    public function getCountries(?string $locale = null): Countries
    {
        return $this->_getLocaleCache(__METHOD__, $locale, fn () => $this->_getIsoCodes($locale)->getCountries());
    }

    /**
     * @copy LocaleInterface::getCurrencies()
     */
    public function getCurrencies(?string $locale = null): Currencies
    {
        return $this->_getLocaleCache(__METHOD__, $locale, fn () => $this->_getIsoCodes($locale)->getCurrencies());
    }

    /**
     * @copy LocaleInterface::getLanguages()
     */
    public function getLanguages(?string $locale = null): LanguagesInterface
    {
        return $this->_getLocaleCache(__METHOD__, $locale, fn () => $this->_getIsoCodes($locale)->getLanguages());
    }

    /**
     * @copy LocaleInterface::getScripts()
     */
    public function getScripts(?string $locale = null): Scripts
    {
        return $this->_getLocaleCache(__METHOD__, $locale, fn () => $this->_getIsoCodes($locale)->getScripts());
    }
    

    /**
     * Translates the texts
     */
    protected function translate(string $key, ?int $number, array $params, ?string $locale): string
    {
        if (($key = trim($key)) === '') {
            return '';
        }

        $locale ??= $this->getLocale();
        $localeBundle = $this->getBundle($locale);
        $value = $number === null ? $localeBundle->translateSingular($key, $params) : $localeBundle->translatePlural($key, $number, $params);
        if ($value ?? HookRegistry::call('Locale::translate', [&$value, $key, $params, $number, $locale, $localeBundle])) {
            return $value;
        }

        error_log("Missing locale key \"${key}\" for the locale \"${locale}\"");
        return is_callable($this->missingKeyHandler) ? ($this->missingKeyHandler)($key) : '##' . htmlentities($key) . '##';
    }

    /**
     * Retrieves a cached item only if it belongs to the current locale. If it doesn't exist, the getter will be called
     */
    private function _getLocaleCache(string $key, ?string $locale, callable $getter)
    {
        if (($locale ??= $this->getLocale()) !== $this->getLocale()) {
            return $getter();
        }
        if (!isset($this->cache[$key][$locale])) {
            // Ensures the previous cache is cleared
            $this->cache[$key] = [$locale => $getter()];
        }
        return $this->cache[$key][$locale];
    }

    /**
     * Given a locale folder, retrieves all locale files (.po)
     *
     * @return int[]
     */
    private function _getLocaleFiles(string $folder, string $locale, int $priority): array
    {
        $files = $this->localeFiles[$folder][$locale] ?? null;
        if ($files === null) {
            $files = [];
            if (is_dir($path = "${folder}/${locale}")) {
                $directory = new RecursiveDirectoryIterator($path);
                $iterator = new RecursiveIteratorIterator($directory);
                $files = array_keys(iterator_to_array(new RegexIterator($iterator, '/\.po$/i', RecursiveRegexIterator::GET_MATCH)));
            }
            $this->localeFiles[$folder][$locale] = $files;
        }
        return array_fill_keys($files, $priority);
    }

    /**
     * Retrieves the request
     */
    private function _getRequest(): PKPRequest
    {
        return app(PKPRequest::class);
    }

    /**
     * Retrieves the ISO codes factory
     */
    private function _getIsoCodes(string $locale = null): IsoCodesFactory
    {
        return app(IsoCodesFactory::class, $locale ? ['locale' => $locale] : []);
    }
}
