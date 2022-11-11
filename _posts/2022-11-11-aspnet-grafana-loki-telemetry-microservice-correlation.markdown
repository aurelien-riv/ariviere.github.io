---
layout: default
title: "Sending ASP.Net Core logs to Grafana Loki with microservice correlation"
description: "Using Grafana Loki as an application log server in a microservice architecture"
excerpt: "Let correlate our logs from multiple microservices in Loki with a correlation id"
date: 2022-11-09 23:52:00 +0200
categories: [AspNetCore]
tags: [AspNetCore, C#, OpenTelemetry, Grafana, Loki, Microservices]
---

# Adding a correlation id to our logs sent to Loki 

In the previous post, we were able to send our logs to Loki using Serilog, which is not really useful in a monolithic 
architecture, compared to our plain text log files. Now, let see how to share a correlation id across our http requests
so that we can search the logs for a specific request through all the services that were consumed.

## Getting / defining our variables

First, here is my class for getting or defining my labels. 

The CorrelationId is a unique identifier, passed to each http requests.

The RootInitiator defines the first item in the chain, being either the frontend, or the api gateway / backend for frontend. 

The AppName is the name of my startup project, but you can of course use another name.

```cs
using System.Diagnostics;
using System.Reflection;

namespace Ari.LokiMicroservices.Logs;

public static class LogAndTraceMetadata
{
    public const string correlationIdKey = "x-correlation-id";
    public const string rootInitiatorKey = "x-root-initiator";

    public static string GetCorrelationId(HttpContext httpContext)
    {
        string? correlationId = null;
        if (httpContext.Request.Headers.TryGetValue(correlationIdKey, out var values))
        {
            correlationId = values.FirstOrDefault();
        }

        return correlationId ?? Activity.Current?.RootId ?? httpContext.TraceIdentifier;
    }

    public static string GetRootInitiator(HttpContext httpContext)
    {
        string? rootInitiator = null;
        if (httpContext!.Request.Headers.TryGetValue(rootInitiatorKey, out var initiatorValues))
        {
            rootInitiator = initiatorValues.FirstOrDefault();
        }
        return rootInitiator ?? GetAppName();
    }

    public static string GetAppName()
    {
        return Assembly.GetCallingAssembly().GetName().Name!;
    }
}
```

Now, we need to enrich our logs with these labels:

```cs
using Serilog.Core;
using Serilog.Events;

namespace Ari.LokiMicroservices.Logs;

public class CorrelationIdEnricher : ILogEventEnricher
{
    private readonly IHttpContextAccessor _contextAccessor;

    public CorrelationIdEnricher() : this(new HttpContextAccessor())
    {
    }

    internal CorrelationIdEnricher(IHttpContextAccessor contextAccessor)
    {
        _contextAccessor = contextAccessor;
    }

    public void Enrich(LogEvent logEvent, ILogEventPropertyFactory propertyFactory)
    {
        logEvent.AddOrUpdateProperty(new LogEventProperty("App", new ScalarValue(LogAndTraceMetadata.GetAppName())));

        if (_contextAccessor.HttpContext == null)
            return;

        logEvent.AddOrUpdateProperty(new LogEventProperty("CorrelationId", new ScalarValue(LogAndTraceMetadata.GetCorrelationId(_contextAccessor.HttpContext!))));
        logEvent.AddOrUpdateProperty(new LogEventProperty("RootInitiator", new ScalarValue(LogAndTraceMetadata.GetRootInitiator(_contextAccessor.HttpContext!))));
    }
}
```

The App label is now defined in the enricher, so can remove the labels property from your appsettings:
```json
{
          "labels": [
            {
              "key": "App",
              "value": "My Application"
            }
          ]
}
```

And then, we have to add our enricher to Serilog configuration, but also to expose the HttpContext so that we can grab 
the values sent by the previous app or site of the chain.

```cs
# Program.cs

builder.Services.AddHttpContextAccessor();

var logger = new LoggerConfiguration()
  .ReadFrom.Configuration(builder.Configuration)
  .Enrich.With<CorrelationIdEnricher>()
  .CreateLogger();
builder.Logging.ClearProviders();
builder.Logging.AddSerilog(logger);
```

Now, we're done with the logger configuration. 

## Transmitting our metadata to the subsequent requests

If your project makes no HTTP requests, you can stop here, but a backend for frontend still has to forward its labels to
its dependencies. To do that, we have to set the header propagation mechanism up:

```cs
# Program.cs

builder.Services.AddHeaderPropagation(options => {
    options.Headers.Add("dmc-correlation-id", context => LogAndTraceMetadata.GetCorrelationId(context.HttpContext));
    options.Headers.Add("dmc-root-initiator", context => LogAndTraceMetadata.GetRootInitiator(context.HttpContext));
});

//[...]

app.UseHeaderPropagation();
```

Now, your labels should be transmitted properly, and Loki should receive them all.

## Indexing the labels

We are sending additional fields to Loki, but they are only part of the log context, and are not indexed. That means you
cannot query them directly, as Loki has to parse the log content first. Add the fields you want to the propertiesAsLabels 
property in the appsettings in order to index them. Use as few fields as you can, and avoid indexing dynamic fields, such as the 
correlation id, to keep Loki fast. I currently only index App and RootInitiator.