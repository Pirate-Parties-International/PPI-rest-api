# Contributing

## Static pages

Static pages (like /faq) can be found in [/src/AppBundle/Resources/staticContent](src/AppBundle/Resources/staticContent).

They are dynamically loaded and use [markdown](https://github.com/adam-p/markdown-here/wiki/Markdown-Cheatsheet).

If you wish to add a new static page just add a file. It's filename will translate to the url.

    integrations.md --> /integrations

## Menu

Menu must be edited in the [base template](app/Resources/views/base.html.twig).

    /app/Resources/views/base.html.twig

## Template

Website was created with [Materializecss](http://materializecss.com/).