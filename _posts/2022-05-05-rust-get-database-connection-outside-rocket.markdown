---
layout: default
title: "Get a Diesel database connection from a non-Rocket binary"
description: "How to get a Diesel database connection with parameters from Rocket.toml in a non Rocket app?"
excerpt: "How to get a Diesel database connection with parameters from Rocket.toml in a non Rocket app?"
date: 2022-05-05 22:40:00 +0200
categories: [Rust]
tags: [Rocket, Diesel]
---

# How to get a Diesel connexion from a non Rocket application ?

Disclaimer: I'm not a Rocket, Diesel, or even Rust expert, my solution may not be seen as ideal, but I use it on 
[mtpki][mtpki] without experiencing any issue.

## Accessing the database from Rocket

If you already had a look to [Rocket's guide (v0.5-rc)][rocket_db_guide], you should already be familiar with that, but here's a reminder:

First, You add `rocket_sync_db_pools` to your dependencies, configure your `Rocket.toml` or some environment variables,
and then define a wrapper structure for your DBMS, such as `Diesel::SqliteConnection`, decorated by a `database`
annotation, telling `rocket_sync_db_pools` which configuration to read from your `Rocket.toml`.

Finally, you are ready to add a parameter of your wrapper struct type, so that your connection provider is injected in 
the controller methods that need it.

However, if you wish to get it from another binary, that method isn't applicable, as there is nothing like request 
guards for non-Rocket applications.

## Accessing the database from anywhere

Suppose you have another binary, either from another workspace or simply in a `bin/` directory, that needs to connect to
your database. You can instantiate the appropriate Diesel struct, either providing the parameters hardcoded, or exported
in an environment variables (`ROCKET_DATABASES`, to use the same name as the one Rocket loads internally), or read it 
from a TOML file for instance.

Using Figment, we can load the configuration both from the `Rocket.toml` file and the`ROCKET_*` environment variables 
in one step. All we have to do is loading the configuration and pass it to Diesel like that:

```rust
use rocket::config::Config;
use serde::Deserialize;
use diesel::{Connection, SqliteConnection};

pub type MyDb = SqliteConnection;

#[derive(Deserialize)]
struct CnxDbSettings {
    url: String
}

pub fn get_db_path() -> String {
    if let Ok(config) = Config::figment().focus("databases.db").extract::<CnxDbSettings>() {
        config.url
    } else {
        panic!("No database url given, or Rocket.toml file not found");
    }
}

pub fn get_db() -> MyDb {
    SqliteConnection::establish(&get_db_path()).unwrap()
}
```

As you can see, the `get_db()` function returns a MyDb instance - a real SqliteConnection - that you can use even
without adding `rocket_sync_db_pools` to your binary's dependencies.

## Get rid of `rocket_sync_db_pools`?

Using that code, you could also get rid of `rocket_sync_db_pools` in your rocket app if you wish, but keep in mind that:
- your app will not scale well (but you'll probably never have to handle enough requests per seconds to notice it), 
- you'll have to call `get_db()` in each controller method that needs an instance, instead of relying on the request guard,
unless you create your own guard.

[mtpki]: https://gitlab.com/aurelien-riv/my-tiny-pki
[rocket_db_guide]: https://rocket.rs/v0.5-rc/guide/state/#databases

