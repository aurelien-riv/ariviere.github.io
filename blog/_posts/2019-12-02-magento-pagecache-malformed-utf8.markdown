---
layout: default
title:  "Magento Page Cache : Unable to serialize value. Error: Malformed UTF-8 characters, possibly incorrectly encoded"
date:   2019-12-02 09:00:00 +0200
categories: [magento, debugging, UTF-8]
permalink: /:year/:month/:day/:title/
---

If you're using Magento's Full Page Cache module, you may encounter that exception :
> **There has been an error processing your request**
>
> Unable to serialize value. Error: Malformed UTF-8 characters, possibly incorrectly encoded
>
> Error log record number: 300877676469

If you open /var/report/300877676469, changes are you get a message like that :
{% highlight JSON %}
{
  "0": "Unable to serialize value. Error: Malformed UTF-8 characters, possibly incorrectly encoded",
  "1": "
    <pre>
      #1 Magento\\Framework\\App\\PageCache\\Kernel->process(&Magento\\Framework\\App\\Response\\Http\\Interceptor#000000004d559138000000002f5a6de4#) called at [vendor/magento/module-page-cache/Model/Controller/Result/BuiltinPlugin.php:98]\n
      #2 Magento\\PageCache\\Model\\Controller\\Result\\BuiltinPlugin->afterRenderResult(&Magento\\Framework\\View\\Result\\Page\\Interceptor#000000004d559a5b000000002f5a6de4#, &Magento\\Framework\\View\\Result\\Page\\Interceptor#000000004d559a5b000000002f5a6de4#, &Magento\\Framework\\App\\Response\\Http\\Interceptor#000000004d559138000000002f5a6de4#) called at [vendor/magento/framework/Interception/Interceptor.php:146]\n
      #3 Magento\\Framework\\View\\Result\\Page\\Interceptor->Magento\\Framework\\Interception\\{closure}(&Magento\\Framework\\App\\Response\\Http\\Interceptor#000000004d559138000000002f5a6de4#) called at [vendor/magento/framework/Interception/Interceptor.php:153]\n
      #4 Magento\\Framework\\View\\Result\\Page\\Interceptor->___callPlugins('renderResult', array(&Magento\\Framework\\App\\Response\\Http\\Interceptor#000000004d559138000000002f5a6de4#), array(array('result-messages', 'result-builtin-c...', 'result-varnish-c...'))) called at [generated/code/Magento/Framework/View/Result/Page/Interceptor.php:39]\n
      #5 Magento\\Framework\\View\\Result\\Page\\Interceptor->renderResult(&Magento\\Framework\\App\\Response\\Http\\Interceptor#000000004d559138000000002f5a6de4#) called at [vendor/magento/framework/App/Http.php:141]\n
      #6 Magento\\Framework\\App\\Http->launch() called at [vendor/magento/framework/App/Bootstrap.php:261]\n
      #7 Magento\\Framework\\App\\Bootstrap->run(&Magento\\Framework\\App\\Http\\Interceptor#000000004d559136000000002f5a6de4#) called at [index.php:39]\n</pre>",
  "url": "/parfum-4711-4711-eau-de-cologne-mixte-7342504005",
  "script_name": "/index.php"
}
{% endhighlight %}

The error appears to come from a plugin from the Page Cache Magento module. 
Let edit the plugin a bit to make it log the problematic string (don't forget to roll back the modification after the debug session) :

{% highlight php %}
	# \Magento\PageCache\Model\Controller\Result\BuiltinPlugin::afterRenderResult
	# defined of vendor/magento/module-page-cache/Model/Controller/Result/BuiltinPlugin.php
	# Line 96
	try {
		$this->kernel->process($response);
	} catch (\Exception|\Error $e) {
	    file_put_contents("PROJECT/var/log/pagecache-" . time(), (string)$response);
	    throw $e;
	}
{% endhighlight %}

You'll get the HTML that was fetched from the cache logged to var/log/pagecache- followed by the current timestamp.
Now we can use grep that log to print the lines that contains invalid UTF-8 characters :

{% highlight shell %}
	$ grep -axv '.*'  var/log/pagecache-1575036624
	</script><meta name="description" content="Something went wrong with my descripti">
{% endhighlight %}

Seems the rendering of our meta description is responsible for the crash. Now let's go to the code that prints that tag:

{% highlight php %}
	<?php substr($description, 0, 150); ?> 
{% endhighlight %}

substr truncates the string regardless the encoding. Usually characters are encoded with a single byte, but UTF-8 character may be composed of two, three or even four bytes.
Using substr here works only works if the 150th byte is not part of a multibyte character, and won't behave as expected (but won't cause a crash) if the string contains multibyte characters before the 150th byte.

Replacing substr with [mb_substr][mb_substr (PHP Doc)] solves the problem :
{% highlight php %}
	<?php mb_substr($description, 0, 150); ?> 
{% endhighlight %}

[mb_substr (PHP Doc)]: https://www.php.net/manual/fr/function.mb-substr.php
