# AI provider identity assets

These local files are copied verbatim from repositories controlled by the corresponding provider organizations. The application selects them only by the normalized provider driver ID; it never derives a logo from a configured provider name or endpoint URL.

| Driver ID | Local file | Verified official source |
| --- | --- | --- |
| `openai` | `openai.svg` | [OpenAI Cookbook — chatgpt-blossom-black.svg, pinned source commit `8ef2841809e1dae17e43cc363e1add95495c2e8d`](https://github.com/openai/openai-cookbook/blob/8ef2841809e1dae17e43cc363e1add95495c2e8d/examples/voice_solutions/realtime_translation_guide/livekit-translation-demo/public/brand/chatgpt-blossom-black.svg) |
| `openrouter` | `openrouter.svg` | [OpenRouter docs — logo-v2-light.svg, pinned source commit `9f25c6b911f1bd13ab3ba9ea60b25b1df8d3054c`](https://github.com/OpenRouterTeam/docs/blob/9f25c6b911f1bd13ab3ba9ea60b25b1df8d3054c/assets/logo-v2-light.svg) |
| `gemini` | `gemini.png` | [Google Gemini Cookbook — gemini_logo.png, pinned source commit `3e0a736b9f6571e6b283b9bc09f78084fcd7c33b`](https://github.com/google-gemini/cookbook/blob/3e0a736b9f6571e6b283b9bc09f78084fcd7c33b/quickstarts/file-api/sample_data/gemini_logo.png) |
| `anthropic` | `anthropic.svg` | [Anthropic TypeScript SDK — .github/logo.svg, pinned source commit `7b4849bdca37cf2f28b4a1916495fa18367b081e`](https://github.com/anthropics/anthropic-sdk-typescript/blob/7b4849bdca37cf2f28b4a1916495fa18367b081e/.github/logo.svg) |

`openai_compatible` intentionally has no logo: its configured endpoint can represent LM Studio, Groq, Together, vLLM, or another custom service, so the UI uses the visible text fallback **Custom**. Unrecognized driver IDs use the same safe text fallback.

The SVGs are rendered as local images with their intrinsic proportions preserved. Gemini's official 512 × 512 PNG is also displayed with `object-fit: contain`; no logo is stretched or cropped. No image library or remote asset request is added at runtime.
