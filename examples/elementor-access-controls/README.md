# Classic and Atomic Elementor access controls

Dastresi-Ban adds editor controls for access conditions on supported Elementor widgets. The Classic and Atomic editors share the same definitions. The Atomic adapter maps those fields to typed properties and keeps presentation-only controls out of saved settings.

| Classic heading | Atomic heading |
| --- | --- |
| ![Access controls in the Classic Elementor heading panel](docs/classic-controls.png) | ![Access controls in the Atomic Elementor heading panel](docs/atomic-controls.png) |

The sample is taken from the Dastresi-Ban 2.0.0 schema adapter and settings codec. The test substitutes small editor contracts to check defaults, property types and preservation of the stored access rule in public rendering. The full plugin evaluates the rule on the server.

    php tests/check.php
