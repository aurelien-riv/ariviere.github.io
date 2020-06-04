---
layout: default
title:  "Magento2: Identify useless HTML blocks in your cache"
date:   2020-06-04 02:00:00 +0200
categories: [magento2]
tags: [performance, cache, redis]
description: Some blocks are cached but never read and pollute your cache, but we can identify them!
excerpt: Some blocks are cached but never read and pollute your cache, but we can identify them!
---

# Identify cached HTML blocks Magento never reads

## Why are some blocks stored in cache but never fetched?

### Back to basics

When you render an HTML block, by yourself using the layout component and calling its toHtml() or the XML layout files, 
Magento will - if the Block HTML cache is enabled - get its cache lifetime. If that lifetime is strictly positive,
Magento will get its cache key and ask the cache backend (var/cache, memcached, Redis, ...) for the stored text.

If the cache backend doesn't have that key, or if that key expired, the _toHtml() method will be called, and your template
will be executed if you didn't override the _toHtml(). If your block embed child blocks - and it does, most of the time -, 
the process will be repeated for that child.

However, if the cache backend has non-expired data for that key, the HTML will be directly returned and its _toHtml()
won't be run (and by extension the phtml template won't be included). Thus, the children will never be instantiated if 
the parent block is stored in the cache.

### What would happen if all your blocks were cacheable?

Let suppose we have a block A, that has three children, B, C and D. 
We also have a block Q which has three children too: R and S, but also D, as A does.
A is used on page P1, and B on page P2.  B, C, D, R and S are never used elsewhere.

If your cache are cold, if you open P1 :
- A is not in the cache, so we generate that block
  - B is not in the cache, so we generate that block too, and cache it
  - C is not in the cache, so we generate that block too, and cache it too
  - D is not in the cache, so we generate that block too, and cache it too
- we save A in the cache

If you open P1 again :
- A is stored in the cache, Magento returns it. B, C and D were not used!

If you open P2 :
- Q is not in the cache, so we generate that block
  - R is not in the cache, so we generate that block too, and cache it
  - S is not in the cache, so we generate that block too, and cache it too
  - D is in  the cache, Magento returns it
- we save Q in the cache

So we can identify three cases:
- A will be loaded every time P1 is opened, and Q every time P2 is opened (unless full page cache is active), so they deserve to be cached;
- B, C, R and S will never be used: their content is embedded in their parent block. As they are no other uses, there is no reason to cache them as they pollute the cache.
- D won't be fetched once both A and Q are loaded. *It may or may not be useful to cache it*, according to the time you gain, the amount of data you store and its lifetime.

### Why is it a problem?

Having unused data in the cache is not a serious problem, however it takes up disk or RAM, and that waste of memory will cause more evictions or force you to upgrade your hardware.
Fixing that may save you money.

# How to locate the blocks that are rarely read?

## A patch to collect the data we need 

Magento doesn't provide any mechanism to spot these blocks, so I created a basic patch to store the data we need to detect them.
You have to register it in your composer.json and install magento/framework again so it is patched. More information in the [README of cweagans/composer-patches].

{% highlight diff %}
diff --git a/View/Element/AbstractBlock.php b/View/Element/AbstractBlock.php
index e6f8ba5..9bf982a 100644
--- a/View/Element/AbstractBlock.php
+++ b/View/Element/AbstractBlock.php
@@ -27,6 +27,8 @@ use Magento\Framework\DataObject\IdentityInterface;
  */
 abstract class AbstractBlock extends \Magento\Framework\DataObject implements BlockInterface
 {
+    use BlockCacheStatsTrait;
+
     /**
      * Cache group Tag
      */
@@ -219,6 +221,8 @@ abstract class AbstractBlock extends \Magento\Framework\DataObject implements Bl
         }
         parent::__construct($data);
         $this->_construct();
+
+        $this->initCacheStats();
     }
 
     /**
@@ -1096,6 +1100,7 @@ abstract class AbstractBlock extends \Magento\Framework\DataObject implements Bl
                 $this->inlineTranslation->suspend($this->getData('translate_inline'));
             }
 
+            $this->addMiss();
             $this->_beforeToHtml();
             return $this->_toHtml();
         };
@@ -1108,7 +1113,11 @@ abstract class AbstractBlock extends \Magento\Framework\DataObject implements Bl
             return $html;
         }
         $loadAction = function () {
-            return $this->_cache->load($this->getCacheKey());
+            $result = $this->_cache->load($this->getCacheKey());
+            if ($result !== false) {
+                $this->addHit();
+            }
+            return $result;
         };
 
         $saveAction = function ($data) {
diff --git a/View/Element/BlockCacheStatsTrait.php b/View/Element/BlockCacheStatsTrait.php
new file mode 100644
index 0000000..ea6931a
--- /dev/null
+++ b/View/Element/BlockCacheStatsTrait.php
@@ -0,0 +1,122 @@
+<?php
+
+namespace Magento\Framework\View\Element;
+            $result = $this->_cache->load($this->getCacheKey());
+            if ($result !== false) {
+                $this->addHit();
+            }
+            return $result;
         };
 
         $saveAction = function ($data) {
diff --git a/View/Element/BlockCacheStatsTrait.php b/View/Element/BlockCacheStatsTrait.php
new file mode 100644
index 0000000..ea6931a
--- /dev/null
+++ b/View/Element/BlockCacheStatsTrait.php
@@ -0,0 +1,122 @@
+<?php
+
+namespace Magento\Framework\View\Element;
+
+/*
+ * sqlite3 cachestats.db
+ * select round((sum(hits) * 1.0) / (sum(hits) + sum(misses)), 3), class, SUM(hits), SUM(misses)
+ * FROM block_html_cstats
+ * WHERE cacheable = 1
+ * GROUP BY class
+ * ORDER BY (sum(hits) * 1.0) / (sum(hits) + sum(misses));
+ */
+trait BlockCacheStatsTrait
+{
+    /** @var \PDO */
+    static protected $cacheStats;
+
+    abstract public function getCacheKey();
+
+    public function initCacheStats()
+    {
+        if (static::$cacheStats === null) {
+            static::$cacheStats = new \PDO('sqlite:' . __DIR__ . "/../../../../../var/cachestats.db");
+            static::$cacheStats->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
+            static::$cacheStats->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
+
+            static::$cacheStats->query(
+                "CREATE TABLE IF NOT EXISTS block_html_cstats (
+                    class     VARCHAR(255) NOT NULL,
+                    key       VARCHAR(255) NOT NULL UNIQUE,
+                    hits      INTEGER DEFAULT 0,
+                    misses    INTEGER DEFAULT 0,
+                    cacheable INTEGER(1) NOT NULL
+                );"
+            );
+        }
+    }
+
+    private function getCurrentHits(string $cacheKey)
+    {
+        $stmt = static::$cacheStats->prepare("
+            SELECT hits
+            FROM block_html_cstats
+            WHERE key = :key
+        ");
+        $stmt->execute([
+            'key'   => $cacheKey
+        ]);
+        $result = $stmt->fetch();
+        return $result === false ? false : $result['hits'];
+    }
+
+    private function getCurrentMisses(string $cacheKey)
+    {
+        $stmt = static::$cacheStats->prepare("
+            SELECT misses
+            FROM block_html_cstats
+            WHERE key = :key
+        ");
+        $stmt->execute([
+            'key'   => $cacheKey
+        ]);
+        $result = $stmt->fetch();
+        return $result === false ? false : $result['misses'];
+    }
+
+    public function addHit()
+    {
+        $cacheKey = $this->getCacheKey();
+        $current = $this->getCurrentHits($cacheKey);
+
+        if ($current === false) {
+            $stmt = static::$cacheStats->prepare("
+                INSERT INTO block_html_cstats (class, key, hits, misses, cacheable)
+                VALUES (:class, :key, 1, 0, :cacheable)"
+            );
+            $stmt->execute([
+                'class' => get_class($this),
+                'key'   => $cacheKey,
+                'cacheable' => $this->getCacheLifetime() > 0 ? 1 : 0
+            ]);
+        } else {
+            $stmt = static::$cacheStats->prepare("
+                UPDATE block_html_cstats
+                SET hits = :hits
+                WHERE key = :key
+            ");
+            $stmt->execute([
+                'key'   => $cacheKey,
+                'hits'  => $current + 1
+            ]);
+        }
+    }
+
+    public function addMiss()
+    {
+        $cacheKey = $this->getCacheKey();
+        $current = $this->getCurrentMisses($cacheKey);
+
+        if ($current === false) {
+            $stmt = static::$cacheStats->prepare("
+                INSERT INTO block_html_cstats (class, key, hits, misses, cacheable)
+                VALUES (:class, :key, 0, 1, :cacheable)"
+            );
+            $stmt->execute([
+                'class' => get_class($this),
+                'key'   => $cacheKey,
+                'cacheable' => $this->getCacheLifetime() > 0 ? 1 : 0
+            ]);
+        } else {
+            $stmt = static::$cacheStats->prepare("
+                UPDATE block_html_cstats
+                SET misses = :misses
+                WHERE key = :key
+            ");
+            $stmt->execute([
+                'key'   => $cacheKey,
+                'misses'  => $current + 1
+            ]);
+        }
+    }
+}
\ No newline at end of file

{% endhighlight %}


## Collecting data

Then, disable full\_page, clear block\_html, and browse your website. The more pages you open the more accurate your stats will be!

## Exploiting the gathered data

There should now be a /var/cachestats.db file. Let open it:

```
$ sqlite3 cachestats.db

```

Now you can query the block classes that are cacheable and have a low hit rate:

```
sqlite> select round((sum(hits) * 1.0) / (sum(hits) + sum(misses)), 3), class, sum(hits), sum(misses) FROM block_html_cstats where cacheable = 1 group by class order by (sum(hits) * 1.0) / (sum(hits) + sum(misses));
0.0|MyVendor\CustomBlocks\Block\CustomContent\CustomDouble\Interceptor|0|1
0.0|MyVendor\CustomBlocks\Block\CustomContent\Custom\Interceptor|0|2
0.0|WeltPixel\OwlCarouselSlider\Block\Slider\Category\Interceptor|0|1
0.222|MyVendor\Header\Block\Html\TopMenu\Interceptor|4|14
0.25|MyVendor\Category\Block\Navigation\CategoryNavigation\Interceptor|2|6
0.471|MyVendor\CustomBlocks\Block\Product\Product\Interceptor|99|111
0.889|Magento\Theme\Block\Html\Footer|8|1
```

You could also query the block classes that are not cacheable and often generated, but if you want to make them cacheable be careful to the cache key.

```
sqlite> select class, sum(misses) FROM block_html_cstats where cacheable = 0 group by class order by sum(misses);
```

## Going further

You can modify the patch to add the generation time and the size of the data, to help you determine whether a class deserve caching.

---
[README of cweagans/composer-patches]: https://github.com/cweagans/composer-patches/blob/master/README.md
