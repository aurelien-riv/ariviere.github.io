---
layout: default
title: "Magento2: Can you use custom namespaces?"
date: 2019-12-15 15:20:00 +0200
categories: [magento2]
tags: [magento1]
description: Magento2 has a strange directory structure inherited from Magento1. Can we get rid of that and organize our class better? It depends...
excerpt: Magento2 has a strange directory structure inherited from Magento1. Can we get rid of that and organize our class better? It depends...
---

# Can you use custom namespaces with Magento2?

## A structure inherited from Magento1

Magento2 inherits most of its directory layout from Magento1, which didn't use namespaces (its development began before namespaces were implemented in PHP). 

To load a helper, we had to use Mage::helper('modulename/classname') or even Mage:helper('modulename') which loaded the "Data" helper of the module. If you wanted to know why many modules have a Helper\Data in their directory, you got it, it's an heritage of Magento1!

To get a new model instance, you had Mage::getModel('modulename/modelname'). The Collection? Simple ask the model to give it yo you. Don't want a new instance, use getSingleton! 

And if you wonder why some modules put there Cron runners in the Model, guess what, that's only because Magento1 cron configuration asked which model runs a crontab entry, even if a Model not related to data and executable is a nonsense!

As Magento1 loads everything for you, it expected the file to be put in a specific directory, and you had no choice other than putting your file where Magento will look for them, or to include them by yourself, which is not the best idea you could have.

## Why should you / shouldn't you move the files from the standard Magento2 hierarchy?

Basically, you shouldn't. It is only going to make maintenance more complex to the guy that will maintain your code later. Remember your first time with Magento? Do you really want to make your next teammate's learning curse even worse by making him read tutorial that won't apply to your code? If you do, you're a mean one :(

Plus, it may cause issues and can lead to regressions after upgrades (and believe me, Magento's upgrades are not your friend...).

So, would would you move these files? Well, some class are traditionally put in the wrong directory, like WebApi controllers, or have an irrelevant name once imported, like Collections. Renaming and moving these can lead to more readable code with few risks.

## What you can move

### Helpers

Clearly the less risky of these. Putting your helpers in the Helper namespace **is a good thing**. Ok but if it was safe but irrelevant, I wouldn't have added to that list, so why move them ?

First you can use subdirectories. If you have plenty of helpers dealing with different business entities, you may group them. Then, maybe you could make a library providing these Helpers and discard the name of Helper. 

Also, and even if it is a bit off topic, do not:
* Use $this->helper() in your templates as it is equivalent to give an object manager directly to the view. You should only expose the methods from the Helper you want in your Block (as a proxy). Basically you should never use $this in your templates but only $block, as $this will forward your calls to $block except for the methods "helper" and "render" which are defined in $this (which is an instance of \Magento\Framework\View\TemplateEngine\Php). The performance gain won't be huge, but it will make the debugging slightly less painful and avoid bugs, such as $this->render() which gives a \TypeError instead of calling $block->render().
* Extend AbstractHelper unless you need it. Most of the time you won't use the dependencies AbstractHelper requires so don't extending it will reduce (a tiny bit) the memory footprint of your class instances, and will reduce the amount of arguments your constructors will need.

### WebApi controllers

When you define a WebApi route, you associate it to a "service": a method from a class or an interface.

By convention, it is bound to an interface located in the Api folder, which is implemented by a concrete class located in the Model.

Most of the time, these webapi will be used for CRUD: they will provide a way to create, read, update or delete entities. For that reason, they are often bound to Repositories methods. 
But if your webapi needs to add some logic, do you still want to put its implementation it in the model ? It would be similar to putting a controller in the model as it manages CRUD.

I use to separate both my WebApi services and the interfaces specific to WebApis to their own directories: \WebApi\Api and \WebApi\Controller.

### Models, ResourceModels, Repositories and Collections

First, I use to move the model classes into Model\Entity, keeping their original class name though. It doesn't make a huge difference, but it only permits to clearly separate things.

ResourceModels share the name of their Model. That means you cannot import both a model and its resource on the same file without using alias. Plus, you can't know at the first glance whether a type hint refers to the model or its resource. For that reason, I prefer naming them Model\Resource\ModelnameResource.

There is no real issue with repositories neither. All of them have a discernible class name, so you can import several of them without a class name collision. However, in order to clearly separate their roles, I use to put them in a Model\Repository namespace.

Collection has an awful class name: you cannot import several collections on the same file without aliasing, which happens way more often than importing a model along with its resource. Plus, once imported, you simply don't know the most important thing: a collection of what? So, let stop the mess and renaming them Model\Collection\ModelnameCollection, so that everything becomes clear. If you want to use getResourceCollection (which is deprecated) from the model class, you'll have to give the entity the new FQCN of your Collection class, by calling \_setResourceModel, for instand in the \_construct method of your entity.

In a nutshell:
```
.
└── Model
    ├── Collection
    │   └── ExampleCollection.php
    ├── Entity
    │   └── Example.php
    ├── Repository
    │   └── ExampleRepository.php
    └── ResourceModel
        └── ExampleResource.php
```

## What you can't easily move

And maybe you just should not try ;)

### Controllers and Routers

Why would you do that? Seriously tell me.

### Blocks, Widgets... Templates in general

Why would you move Blocks away? However, you could want to move Widgets to their own namespace. But what if you try ?  
```
Exception #0 (Magento\Framework\Exception\ValidatorException): Invalid template file: 'mytemplete.phtml' in module: '' block's name: 'my.template'
```
Magento will try to find your template from your module path... computed from your Block's namespace, but discarding everything from \Block. You could try to overload \Magento\Framework\View\Element\AbstractBlock::extractModuleName, but the method being static and called using self:: and not static:: so your implementation would not be called. You'd have to overload \Magento\Framework\View\Element\AbstractBlock::getModuleName instead.

So, let be clear, keep your blocks and widgets where Magento expects you to put them and you'll avoid useless troubles.

### Translations

Even for a second, do **not** think about moving translations! Magento does not mess with i18n files, and if you put them somewhere else, no error will be raised, no log is going to be written, but be sure your file is going to be ignored. I won't explain here how translations are loaded but it deserves a dedicated post.
