# Classic and Atomic Elementor access controls

Dastresi-Ban adds editor controls for access conditions on supported Elementor widgets. The Classic and Atomic editors share the same definitions. The Atomic adapter maps those fields to typed properties and keeps presentation-only controls out of saved settings.

### Classic Heading

The Advanced tab shows an access rule restricted to signed-in users.

![Classic Heading controls from the Advanced tab showing Allow access and Signed-in users only](docs/classic-controls.png)

### Atomic Heading

The General tab shows the same rule on the separate Atomic Heading test page.

![Atomic Heading access controls showing Allow access and Signed-in users only](docs/atomic-controls.png)

[See the Atomic content delivery options](docs/atomic-delivery.png).

The sample is taken from the Dastresi-Ban 2.0.0 schema adapter and settings codec. The test substitutes small editor contracts to check defaults, property types and preservation of the stored access rule in public rendering. The full plugin evaluates the rule on the server.

The captures use an English locale fixture on WordPress 7.1.3 and Elementor 4.3.4. Each editor loaded the rule from its saved document. The published Heading rendered for a subscriber and was absent from the guest response. A separate installed integration fixture checked 30 supported Atomic element types.

    php tests/check.php
