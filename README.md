# semitexa/graphql

Opt-in GraphQL operation discovery for Semitexa, built on the same `Payload DTO -> Handler -> typed output contract` architecture as the rest of the framework.

## Purpose

Provides the GraphQL-ready discovery layer for Semitexa applications without introducing a second application architecture. Operations stay rooted in the existing Semitexa contracts instead of pushing business logic into ad-hoc resolver closures.

## Role in Semitexa

Depends on `semitexa/core` and reuses `RouteInspectionRegistryInterface` to enumerate discovered routes. Payload DTOs explicitly marked with `#[ExposeAsGraphql]` become discoverable GraphQL operations through `GraphqlOperationRegistryInterface`.

This package intentionally starts narrow:

- no magic conversion of every REST route into GraphQL
- no requirement to introduce a separate resolver-centric application layer
- no promise that presenter-built JSON arrays are GraphQL-safe output contracts

## Key Features

- `#[ExposeAsGraphql]` attribute for opt-in GraphQL operation exposure
- `GraphqlOperationRegistryInterface` for enumerating GraphQL-ready operations
- `ResolvedGraphqlOperation` immutable DTO for schema builders and transports
- explicit root type selection: `query` or `mutation`
- explicit typed output contract declaration per operation

## Phase 1 Scope

Phase 1 is discovery-first. It establishes the contracts needed to build a future `POST /graphql` transport and schema builder without pretending those pieces already exist. The goal is to make GraphQL a transport over Semitexa contracts, not a new place where application logic goes to drift.
