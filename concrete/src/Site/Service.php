<?php
namespace Concrete\Core\Site;

use Concrete\Core\Attribute\Key\SiteKey;
use Concrete\Core\Entity\Page\Template;
use Concrete\Core\Entity\Site\Locale;
use Concrete\Core\Entity\Site\Site;
use Concrete\Core\Entity\Site\SiteTree;
use Concrete\Core\Entity\Site\Tree;
use Concrete\Core\Entity\Site\Type;
use Concrete\Core\Localization\Localization;
use Concrete\Core\Page\Theme\Theme;
use Concrete\Core\Site\Resolver\ResolverFactory;
use Doctrine\ORM\EntityManagerInterface;
use Concrete\Core\Application\Application;

class Service
{

    protected $entityManager;
    protected $app;
    protected $config;
    protected $resolverFactory;
    protected $cache;

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function setEntityManager($entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function __construct(EntityManagerInterface $entityManager,
    Application $app, \Illuminate\Config\Repository $configRepository, ResolverFactory $resolverFactory)
    {
        $this->app = $app;
        $this->entityManager = $entityManager;
        $this->config = $configRepository;
        $this->cache = $this->app->make("cache/request");
        $this->resolverFactory = $resolverFactory;
    }

    /**
     * @param mixed $cache
     */
    public function setCache($cache)
    {
        $this->cache = $cache;
    }

    public function getByType(Type $type)
    {
        $sites = $this->entityManager->getRepository('Concrete\Core\Entity\Site\Site')
            ->findByType($type);
        $return = array();
        foreach($sites as $site) {
            $factory = new Factory($this->config);
            $return[] = $factory->createEntity($site);
        }
        return $return;
    }

    public function getByHandle($handle)
    {
        $item = $this->cache->getItem(sprintf('/site/handle/%s', $handle));
        if (!$item->isMiss()) {
            $site = $item->get();
        } else {
            $site = $this->entityManager->getRepository('Concrete\Core\Entity\Site\Site')
                ->findOneBy(['siteHandle' => $handle]);

            $factory = new Factory($this->config);
            if (is_object($site)) {
                $site = $factory->createEntity($site);
            }
            $this->cache->save($item->set($site));
        }
        return $site;
    }

    public function getDefault()
    {
        $item = $this->cache->getItem(sprintf('/site/default'));
        if (!$item->isMiss()) {
            $site = $item->get();
        } else {
            $factory = new Factory($this->config);
            try {
                $site = $this->entityManager->getRepository('Concrete\Core\Entity\Site\Site')
                    ->findOneBy(array('siteIsDefault' => true));
            } catch(\Exception $e) {
                return $factory->createDefaultEntity();
            }
            if (is_object($site)) {
                $site = $factory->createEntity($site);
            }
            $this->cache->save($item->set($site));
        }
        return $site;
    }

    public function add(Type $type, Theme $theme, $handle, $name, $locale, $default = false)
    {
        $factory = new Factory($this->config);
        $site = $factory->createEntity();
        $site->setSiteHandle($handle);
        $site->setIsDefault($default);
        $site->setType($type);
        $site->setThemeID($theme->getThemeID());
        $site->getConfigRepository()->save('name', $name);

        $this->entityManager->persist($site);
        $this->entityManager->flush();

        $data = explode('_', $locale);
        $locale = new Locale();
        $locale->setSite($site);
        $locale->setIsDefault(true);
        $locale->setLanguage($data[0]);
        $locale->setCountry($data[1]);

        $localeService = new \Concrete\Core\Localization\Locale\Service($this->entityManager);
        $localeService->updatePluralSettings($locale);

        $this->entityManager->persist($locale);
        $this->entityManager->flush();

        $this->entityManager->refresh($site);

        return $site;
    }

    public function getSiteTreeByID($siteTreeID)
    {
        return $this->entityManager->getRepository('Concrete\Core\Entity\Site\Tree')
            ->find($siteTreeID);
    }

    public function getByID($id)
    {
        $site = $this->entityManager->getRepository('Concrete\Core\Entity\Site\Site')
            ->find($id);
        $factory = new Factory($this->config);
        if (is_object($site)) {
            return $factory->createEntity($site);
        }
    }

    public function delete(Site $site)
    {

        $attributes = SiteKey::getAttributeValues($site);
        foreach($attributes as $av) {
            $this->entityManager->remove($av);
        }

        $this->entityManager->flush();

        $r = $this->entityManager->getConnection()
            ->executeQuery('select cID from Pages where siteTreeID = ? and cParentID = 0', [$site->getSiteTreeID()]);
        while ($row = $r->fetch()) {
            $page = \Page::getByID($row['cID']);
            if (is_object($page) && !$page->isError()) {
                $page->moveToTrash();
            }
        }

        $locales = $site->getLocales();
        $service = new \Concrete\Core\Localization\Locale\Service($this->entityManager);

        foreach($locales as $locale) {
            $service->delete($locale);
        }

        $this->entityManager->remove($site);
        $this->entityManager->flush();
    }

    public function getList()
    {
        $sites = $this->entityManager->getRepository('Concrete\Core\Entity\Site\Site')
            ->findAll();
        $list = array();
        $factory = new Factory($this->config);
        foreach($sites as $site) {
            $list[] = $factory->createEntity($site);
        }
        return $list;
    }

    public function installDefault($locale = null)
    {
        if (!$locale) {
            $locale = Localization::BASE_LOCALE;
        }

        $siteConfig = $this->config->get('site');
        $defaultSite = array_get($siteConfig, 'default');

        $factory = new Factory($this->config);
        $site = $factory->createEntity();
        $site->setSiteHandle(array_get($siteConfig, "sites.{$defaultSite}.handle"));
        $site->setIsDefault(true);

        $data = explode('_', $locale);
        $locale = new Locale();
        $locale->setSite($site);
        $locale->setIsDefault(true);
        $locale->setLanguage($data[0]);
        $locale->setCountry($data[1]);

        $tree = new SiteTree();
        $tree->setSiteHomePageID(HOME_CID);
        $tree->setLocale($locale);
        $locale->setSiteTree($tree);

        $site->getLocales()->add($locale);

        $service = $this->app->make('site/type');
        $type = $service->getDefault();
        $site->setType($type);

        $localeService = new \Concrete\Core\Localization\Locale\Service($this->entityManager);
        $localeService->updatePluralSettings($locale);

        $this->entityManager->persist($site);
        $this->entityManager->persist($locale);
        $this->entityManager->flush();

        $this->cache->delete('site');

        return $site;
    }

    /**
     * @return Site
     */
    final public function getSite()
    {
        $item = $this->cache->getItem('site');
        if (!$item->isMiss()) {
            $site = $item->get();
        } else {
            $site = $this->resolverFactory->createResolver($this)->getSite();
            $this->cache->save($item->set($site));
        }
        return $site;
    }

    /**
     * @return Site
     */
    final public function getActiveSiteForEditing()
    {
        return $this->resolverFactory->createResolver($this)->getActiveSiteForEditing();
    }

}
