# Changelog: zerofront/module-zero-mail

## 1.3.10

- Fixed: a compiled store (`setup:di:compile`) failed on every request and every `bin/magento` command with "Call to undefined method Zero\Mail\Model\Queue\MessageCodec::__set_state()". Versions 1.3.7 to 1.3.9 have the fault; a store in developer mode without compiled code did not show it.
- If the store is already down: update the package, delete `generated/metadata` and `generated/code`, then run `bin/magento setup:di:compile` again.

## 1.3.6

- The email log also redacts sign-in links, account deletion links and parameters named like `*token`, `*_key`, `apiKey` or `code`; lookalikes such as `hotkey` are kept.

Earlier releases (1.3.5 and before): see the Git tags.
