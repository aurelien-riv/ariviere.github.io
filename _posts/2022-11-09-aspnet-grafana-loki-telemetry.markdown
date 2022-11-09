---
layout: default
title: "Sending ASP.Net Core logs to Grafana Loki - basic configuration"
description: "Using Grafana Loki as an application log server"
excerpt: "Let send our logs to Grafana Loki using Serilog and link them using a correlation id"
date: 2022-11-09 23:52:00 +0200
categories: [ASP.NET Core]
tags: [ASP.NET Core, C#, OpenTelemetry, Grafana, Loki]
---

# Sending ASP.Net Core logs to Grafana Loki

Disclaimer: I am not an ASP.Net expert, and I'm currently discovering Loki and Tempo. I'm not trying to set up a
production-ready telemetry stack, but to test the product and its benefits for my team. 

## What is Loki?

Everyone have heard of Elasticsearch as a log storage and Kibana as a visualization engine. Loki is an alternative to 
Elasticsearch, developed by Grafana Labs. It has the advantage of not eating hundreds of gigabytes of RAM (probably
because it doesn't need a Java Runtime...) and it consumes less disk space and I/O operations when writing. However, 
querying the logs is slower, as only the timestamp and explicitly selected labels are indexed, which implies retrieving
all the log entries matching your query to apply some filters to the message and its context. 

Considering that logs are written all the time but aren't read frequently, and that when you do read them you usually 
know approximately when what you are looking for occurred, slow query time is not a problem to me. 

## Installing Grafana and Loki with Docker Compose

Now, we're ready to set our monitoring stack up. I'll assume you already are familiar with Docker and Docker-Compose.
These files are mainly pasted from Grafana examples, somewhere on their GitHub, with some modifications. 

```yaml
# docker-compose.yaml
version: "3.9"

networks:
  monitoring:

services:
  loki:
    image: grafana/loki:latest
    ports:
      - "3100:3100"
    networks:
      - monitoring

  grafana:
    image: grafana/grafana:latest
    depends_on:
      - loki
    environment:
      - GF_AUTH_ANONYMOUS_ENABLED=true
      - GF_AUTH_ANONYMOUS_ORG_ROLE=Admin
      - GF_AUTH_DISABLE_LOGIN_FORM=true
    ports:
      - "3000:3000"
    networks:
      - monitoring
    volumes:
      - ./grafana-datasources.yaml:/etc/grafana/provisioning/datasources/datasources.yaml
```

```yaml
# grafana-datasources.yaml
apiVersion: 1

datasources:
  - name: Loki
    type: loki
    uid: loki
    access: proxy
    orgId: 1
    url: http://loki:3100
    basicAuth: false
    isDefault: false
    version: 1
    editable: false
    apiVersion: 1
```

Copy these to your file system and run `docker-compose up` from the directory, as usual.

## Instrumenting an ASP.Net Core API

On your Core application, add the nuget packages `Serilog`, `Serilog.AspNetCore`, and `Serilog.Sinks.Grafana.Loki`.

On your `Program.cs`, register Serilog as your Logger. Registering it in your startup project will make any class library
that relies on ILogger to use Serilog.

```cs
var logger = new LoggerConfiguration()
  .ReadFrom.Configuration(builder.Configuration)
  .CreateLogger();
builder.Logging.ClearProviders();
builder.Logging.AddSerilog(logger);
```

Then, add the following to your appsettings.$env.json :

```json
{
  "Serilog": {
    "Using": [
      "Serilog.Sinks.Grafana.Loki"
    ],
    "MinimumLevel": {
      "Default": "Information",
      "Override": {
        "Microsoft.Hosting.Lifetime": "Warning",
        "Microsoft.EntityFrameworkCore": "Warning"
      }
    },
    "WriteTo": [
      {
        "Name": "GrafanaLoki",
        "Args": {
          "uri": "http://localhost:3100",
          "labels": [
            {
              "key": "App",
              "value": "My Application"
            }
          ],
          "propertiesAsLabels": [
            "App"
          ]
        }
      }
    ],
    "Enrich": [
      "FromLogContext"
    ]
  }
}
```

It will add a Loki sink (which doesn't prevent you to also log to a file or the console), add the context, and limit the 
verbosity of some assemblies. Also, note the labels part that adds the name of your application to the log, and the
propertiesAsLabels property that makes your app label an indexed field of the log.

Now, you can open Grafana (http://localhost:3000), go to the *Explore* menu, select the Loki source, select the 
App label and the value "My Application", click the blue button *Run query* in the top right corner. Your logs 
should appear!

That configuration is handy for monolithic applications. In the next article, I will show you how to enrich the logs 
with a correlation id and how to propagate it to your microservices, in order to query the logs for all the related 
requests. 