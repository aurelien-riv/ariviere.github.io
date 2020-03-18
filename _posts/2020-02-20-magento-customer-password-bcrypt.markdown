---
layout: default
title:  "Magento2 and migrated bcrypt passwords"
date:   2020-02-20 13:50:00 +0200
categories: [magento2]
tags: [migration, magento1, bcrypt]
description: Password migrated from Magento1 to Magento2 no longer works, here's a fix.
excerpt: Password migrated from Magento1 to Magento2 no longer works, here's a fix.
---

# Context

SUPEE-11219 security patch for Magento1 replaced the initial password hashing with bcrypt. 
If you migrate your date from Magento 1 to Magento 2, either using Magento's *Data Migration Tool* 
or UberTheme's *UB Data Migration Pro*, these bcrypt hashed passwords will be unusable with Magento2.

[An issue has been opened on GitHub][GitHub#26731], giving a solution, but with no applicable patch.

If you need to fix it, unless you want to fork Magento, I see three solutions:

* either you use *composer-patches* and ask Composer to edit the Encryptor
* or you extend Encryptor and add a preference to your class so you redefine *isValidHash*
* or you make a plugin on Encryptor, around *isValidHash*.

I chose the last solution, which seemed easy to implement, but hid a bug. 
If you need to apply that fix, I strongly recommend you to patch the Encryptor using Composer, which, in that case, would be way easier and safer!

# My solution

## Changing *isValidHash* behavior

Let create a plugin around *isValidHash* from *\Magento\Framework\Encryption\Encryptor* :

{% highlight php %}
<?php

namespace Ari\Customer\Plugin;

use Magento\Framework\Encryption\Encryptor;

/**
 * @see https://github.com/magento/magento2/issues/26731
 */
class PasswordEncryptorBcrypt
{
    public function aroundIsValidHash(Encryptor $subject, callable $proceed, string $password, string $hash)
    {
        if (stripos($hash, '$2y$') === 0) {
	    // migrated password hashes may have a :0 suffix, we drop it to get the real bcrypt hash
            $hash = preg_replace('/:0$/', '', $hash);
            return password_verify($password, $hash);
        }
        return $proceed($password, $hash);
    }
}
{% endhighlight %}

Next, let register it:

{% highlight xml %}
    <type name="Magento\Framework\Encryption\Encryptor">
        <plugin name="passwd_encryptor_bcrypt" type="Ari\Customer\Plugin\PasswordEncryptorBcrypt"/>
    </type>
{% endhighlight %}

Now, customer logging should work as expected... or not.

In CLI:
```
Cache frontend 'default' is not recognized.#0 /home/ari/dev/bs-mg2/vendor/magento/framework/App/Cache/Type/FrontendPool.php(87): Magento\Framework\App\Cache\Frontend\Pool->get('default')
#1 /home/ari/dev/vendor/magento/framework/App/Cache/Type/Config.php(49): Magento\Framework\App\Cache\Type\FrontendPool->get('config')
#2 /home/ari/dev/vendor/magento/framework/Cache/Frontend/Decorator/Bare.php(65): Magento\Framework\App\Cache\Type\Config->_getFrontend()
#3 /home/ari/dev/vendor/magento/framework/Interception/PluginList/PluginList.php(288): Magento\Framework\Cache\Frontend\Decorator\Bare->load('global|primary|...')
#4 /home/ari/dev/vendor/magento/framework/Interception/PluginList/PluginList.php(266): Magento\Framework\Interception\PluginList\PluginList->_loadScopedData()
#5 /home/ari/dev/generated/code/Magento/Framework/Encryption/Encryptor/Interceptor.php(22): Magento\Framework\Interception\PluginList\PluginList->getNext('Magento\\Framewo...', 'getLatestHashVe...')
#6 /home/ari/dev/vendor/magento/framework/Encryption/Encryptor.php(151): Magento\Framework\Encryption\Encryptor\Interceptor->getLatestHashVersion()
[...]
```

In Frontend:
```
LogicException: Circular dependency: WeltPixel\Backend\Model\Logger depends on Magento\Framework\Cache\InvalidateLogger and vice versa. 
	in /home/ari/dev/bs-mg2/vendor/magento/framework/ObjectManager/Factory/Dynamic/Developer.php:55 
Stack trace: 
0 /home/ari/dev/vendor/magento/framework/ObjectManager/ObjectManager.php(70): Magento\Framework\ObjectManager\Factory\Dynamic\Developer->create('WeltPixel\\Backe...') 
1 /home/ari/dev/vendor/magento/framework/ObjectManager/Factory/AbstractFactory.php(160): Magento\Framework\ObjectManager\ObjectManager->get('WeltPixel\\Backe...') 
2 /home/ari/dev/vendor/magento/framework/ObjectManager/Factory/AbstractFactory.php(246): Magento\Framework\ObjectManager\Factory\AbstractFactory->resolveArgument(Array, 'Psr\\Log\\LoggerI...', NULL, 'logger', 'Magento\\Framewo...') 
3 /home/ari/dev/vendor/magento/framework/ObjectManager/Factory/Dynamic/Developer.php(34): Magento\Framework\ObjectManager\Factory\AbstractFactory->resolveArgumentsInRuntime('Magento\\Framewo...', Array, Array) 
4 /home/ari/dev/vendor/magento/framework/ObjectManager/Factory/Dynamic/Developer.php(59): Magento\Framework\ObjectManager\Factory\Dynamic\Developer->_resolveArguments('Magento\\Framewo...', Array, Array) 
5 /home/ari/dev/vendor/magento/framework/ObjectManager/ObjectManager.php(70): Magento\Framework\ObjectManager\Factory\Dynamic\Developer->create('Magento\\Framewo...') 
6 /home/ari/dev/vendor/magento/framework/ObjectManager/Factory/AbstractFactory.php(160): Magento\Framework\ObjectManager\ObjectManager->get('Magento\\Framewo...') 
7 /home/ari/dev/vendor/magento/framework/ObjectManager/Factory/AbstractFactory.php(246): Magento\Framework\ObjectManager\Factory\AbstractFactory->resolveArgument(Array, 'Magento\\Framewo...', NULL, 'logger', 'Magento\\Framewo...') 
8 /home/ari/dev/vendor/magento/framework/ObjectManager/Factory/Dynamic/Developer.php(34): Magento\Framework\ObjectManager\Factory\AbstractFactory->resolveArgumentsInRuntime('Magento\\Framewo...', Array, Array) 
9 /home/ari/dev/vendor/magento/framework/ObjectManager/Factory/Dynamic/Developer.php(59): Magento\Framework\ObjectManager\Factory\Dynamic\Developer->_resolveArguments('Magento\\Framewo...', Array, Array) 
10 /home/ari/dev/vendor/magento/framework/ObjectManager/ObjectManager.php(56): Magento\Framework\ObjectManager\Factory\Dynamic\Developer->create('Magento\\Framewo...', Array) 
11 /home/ari/dev/vendor/magento/framework/App/Cache/Frontend/Factory.php(197): Magento\Framework\ObjectManager\ObjectManager->create('Magento\\Framewo...', Array) 
12 /home/ari/dev/vendor/magento/framework/App/Cache/Frontend/Factory.php(161): Magento\Framework\App\Cache\Frontend\Factory->_applyDecorators(Object(Magento\Framework\Cache\Frontend\Decorator\TagScope)) 
13 /home/ari/dev/vendor/magento/framework/App/Cache/Frontend/Pool.php(67): Magento\Framework\App\Cache\Frontend\Factory->create(Array) 
14 /home/ari/dev/vendor/magento/framework/App/Cache/Frontend/Pool.php(146): Magento\Framework\App\Cache\Frontend\Pool->_initialize() 
15 /home/ari/dev/vendor/magento/framework/App/Cache/Type/FrontendPool.php(87): Magento\Framework\App\Cache\Frontend\Pool->get('default') 
16 /home/ari/dev/vendor/magento/framework/App/Cache/Type/Config.php(49): Magento\Framework\App\Cache\Type\FrontendPool->get('config') 
17 /home/ari/dev/vendor/magento/framework/Cache/Frontend/Decorator/Bare.php(65): Magento\Framework\App\Cache\Type\Config->_getFrontend() 
18 /home/ari/dev/vendor/magento/framework/Interception/PluginList/PluginList.php(288): Magento\Framework\Cache\Frontend\Decorator\Bare->load('global|primary|...') 
19 /home/ari/dev/vendor/magento/framework/Interception/PluginList/PluginList.php(266): Magento\Framework\Interception\PluginList\PluginList->_loadScopedData() 
[...]
```

## Fixing the circular dependency

If we browse the $creationStack and the stack trace when it fails creating the Logger, here can understand what Magento's doing:

![Encryptor and Logger circular reference](/media/magento2-bcrypt-plugin-fail.svg)

Magento's dependency injection fails as the encryptor wants a logger and the logger wants the encryptor. These dependencies are indirect, through other injected classes.

That was not an issue earlier as there was no Interceptor for Encryptor before, we made Magento create it because of our plugin. 

To prevent that, we can postpone the creation of the Logger injected into InvalidateLogger by replacing it with a Proxy class. 
Thus, the logger will be created the first time it is really needed. To do that, we simply need to add a few five lines in our previous di.xml file:

{% highlight xml %}
    <type name="\Magento\Framework\Cache\InvalidateLogger">
        <arguments>
            <argument name="logger" xsi:type="object">Psr\Log\LoggerInterface\Proxy</argument>
        </arguments>
    </type>

    <type name="Magento\Framework\Encryption\Encryptor">
        <plugin name="passwd_encryptor_bcrypt" type="Ari\Customer\Plugin\PasswordEncryptorBcrypt"/>
    </type>
{% endhighlight %}

Of course, don't forget to subscribe to [Magento's issue \#26731][GitHub#26731] to remove that code once it's fixed in your Magento's version.

[GitHub#26731]: https://github.com/magento/magento2/issues/26731
