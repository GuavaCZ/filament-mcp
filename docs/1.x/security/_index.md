---
title: Security
order: 50
---

# Security

An MCP server is a different kind of caller than a browser. The request arrives with a bearer token instead of a session, it never passes through a panel page, and the thing composing the request is a language model that reads untrusted text all day.

That last part is the one worth sitting with. An agent holding a legitimate token can be talked into using it. A prompt injection in an issue comment, a customer email or a PDF it was asked to summarise becomes an instruction, and the token does the rest.

So the useful question is not *"do I trust this user"* but *"what can this token do if the agent behind it is following someone else's instructions"*. The following pages are written to answer that.
