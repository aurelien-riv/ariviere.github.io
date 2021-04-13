---
layout: default
title: "How to use Jetbrain's IDE CLion for Root C++ development?"
description: "Set-up CMake on your Root project so you can use a powerful IDE to be more productive and make a more reliable code"
excerpt: "Set-up CMake on your Root project so you can use a powerful IDE to be more productive and make a more reliable code"
date: 2021-04-13 22:00:00 +0200
categories: [ROOT]
tags: [ROOT, IDE, CMake, Datascience]
---

# How to use Jetbrain's IDE *CLion* for Root C++ development?

Disclaimer: this is not a tutorial on the use of CLion, I'll explain why an IDE is really a must-have,
how to make it suitable for Root C++, but you'll have to learn to use it by yourself. It is quite 
intuitive to use, has a consequent community, and Jetbrain provides loads of documentation for its IDEs.

## Why would I need it?

Okay, it may seem trivial for some of you (for all the developers at least), but many scientists that 
develop analysis or simulations may not be familiar with the notion of IDE. If you use a basic text 
editor, that section is written for you.

First, an IDE is meant to write code, so it supports syntax highlighting, but most of the basic text 
editors also provide that out of the box (well, except Microsoft's Notepad).

Then, an IDE understands the code (and communicates with the compiler for a better understanding), so it
can autocomplete functions and methods name according to the context, or highlight your mistakes (for 
instance a type incompatibility, or the use of an undefined variable).

Moreover, an IDE will usually have it own code inspections, and bundle static code analysis tools so it
can suggest you improvements when you wrote something that will be okay for the compiler but may 
potentially cause a bug.

An IDE will also be able to run, debug and sometime profile your code (which implies it is able to adapt
the build options to the execution context).

Finally, an IDE is a modular and extensible software that integrates several other tools and in which you
can add plug-ins. For instance, it can integrate a database explorer, a to-do list, a terminal, or last but
not least a version control system.

## CLion IDE

CLion is a commercial IDE from Jetbrains, a well-known company in the world of IDEs as it also edits
IDEA, on which is based Android Studio, Resharper (a Visual Studio add-on for C#) or PHPStorm. Unfortunately,
there is no free version for that IDE (unlike IDEA ou PyCharm which are available in a free community edition
in addition to the fully featured commercial edition), but you can use it for free for 30 days.

Installation instructions and screenshots are available on [Jetbrain's CLion webpage][clion-site].

Out of the box, CLion will not understand your ROOT code: it won't know where all the root's headers 
you include are located. That's because root doesn't install its header directly in */usr/include* but on the 
subdirectory */usr/include/root*. That's the reason for the *-l/usr/include/root* on the compiler's command line.

Some IDEs allow you to specify the additional include directories, but CLion doesn't. As some modern IDEs, it relies
on a build system (in its case on CMake) to get that information.

You will need to set up CMake correctly so that CMake can build your project.

## How to build a Root project using CMake?

You'll need to create a file named CMakeLists.txt on the root of your project. Here, two options exist:

### Using find_package

Root manual has a page called "[Integrating ROOT into CMake projects][root-cmake-tuto]", which is not well explained
if you never heard about CMake before. 

I tried that method, and it didn't work, the CMake Root package installed on my system referenced some libraries versions
that didn't exist on my computer, such as */usr/lib64/root/libHistFactory.so.6.22.06*. I'm currently using a development 
version of Fedora, so it may explain why I got that issue, but I had to find another solution.

### Writing CMakeLists.txt ourselves

Far from large projects I use to maintain professionally, my needs were simple, a few C++ files and only Root and a part of 
the standard C++ library, so writing the CMakeLists.txt from scratch was not really complex:

```CMake
cmake_minimum_required(VERSION 3.17)
project(distribution)

set(CMAKE_CXX_STANDARD 14)

find_package(OpenMP)

add_executable(distribution Distribution.C)

target_include_directories(distribution PUBLIC /usr/include/root)

target_link_directories(distribution PUBLIC /usr/lib64/root)
target_link_libraries(distribution Gui)
target_link_libraries(distribution Core)
target_link_libraries(distribution Gpad)
target_link_libraries(distribution RIO)
target_link_libraries(distribution Hist)
#[...]
target_link_libraries(distribution OpenMP::OpenMP_CXX)
```

First, I declare CMake minimal version it needs to be build(1) (the one that is installed on your computer is 
often a good choice), and the name of the project(2).

Then, I set the C++ language version to use for the build(4). It has to be the same as the one that have been 
used to build Root. You can get it by looking at the output of `root-config --cflags`, in my case `-std=c++14`.

I planned to use OpenMP for parallel computing, so I include CMake's OpenMP package(6).

I declare an executable called distribution, which is going to be make from Distribution.C(8). I could have listed 
several C or C++ files here, and it's also possible to automatically get a list of source files using GLOB.

Remember when I said CLion needed a CMakeLists.txt to know in which folders it had to look for the files you include?
Thanks to target\_include\_directories, it will know it has to include */usr/include/root*, and so will your compiler know(10).

Then, you set up the link directories and libraries, which is needed neither by the IDE nor by the compiler, but by the linker.
Without it, your code will compile but not get linked to the libraries and no executable would be made.
First, I tell CMake were Root libraries are stored(12), and then, to which libraries my program should be linked(13-19). 
Line 19 is not related to Root but to OpenMP, for parallel computing (cf find_package). 

I didn't list all the libraries that Root would link by default, represented by the ellipsis #[...]. I only included the one I
really needed. Of course, if the linker says a function implementation is missing, add the necessary libraries. The whole
list can be found in the output of `root-config --libs`.

### Undefined reference to main in function \_start

You don't need a main() function when running your program from Root, however the linker will look for a entry point in your code.
You can use that one, and replace Distribution() with the name of your primary function (the one that has the same name as your file).
```c++
#include <TApplication.h>

int main(int argc, char **argv) {
    TApplication app("app", &argc, argv);
    Distribution();
    app.Run();
    return 0;
}
```

Without *TApplication app*, your visualization would not be displayed on screen, and without *app.Run()* they would close immediately
after being rendered. You may however comment out these two lines when you want to profile your code, as *app.Run()* will prevent your
program from exiting.

[clion-site]: https://www.jetbrains.com/fr-fr/clion/
[root-cmake-tuto]: https://root.cern/manual/integrate_root_into_my_cmake_project/
