<?php
/**
 * AI Auto Work — provider contract.
 *
 * Every adapter is constructed from ONE row of the ai_providers table, so the
 * same driver class can back several configured providers (e.g. two OpenRouter
 * accounts). Adding a new driver means: implement this interface, register it
 * in sh_ai_drivers(). Product AI, Blog AI, SEO AI, Bulk AI never see a driver.
 *
 * Implementations must NEVER throw and must never return a fabricated success:
 * a provider failure is returned as ['ok' => false, 'error' => ..., 'retryable' => bool].
 */
interface AIProviderInterface
{
    /** Driver key, e.g. "openrouter". */
    public function driver(): string;

    /** Configured provider row id (0 when unsaved). */
    public function id(): int;

    /** Display name of this configured provider. */
    public function name(): string;

    /** Does this driver expose any image-generation model at all? */
    public function supportsImages(): bool;

    /**
     * Plain-text completion.
     *
     * @param array $options model, temperature, max_tokens
     * @return array{ok:bool,text?:string,usage?:array,error?:string,retryable?:bool,model?:string}
     */
    public function generateText(string $system, string $user, array $options = []): array;

    /**
     * Ask for a JSON object; the adapter enables JSON mode where the API has one
     * and always returns the decoded array (or a failure).
     *
     * @return array{ok:bool,data?:array,text?:string,usage?:array,error?:string,retryable?:bool,model?:string}
     */
    public function generateStructuredContent(string $system, string $user, array $options = []): array;

    /**
     * Image generation. Returns raw bytes.
     *
     * @return array{ok:bool,binary?:string,mime?:string,usage?:array,error?:string,retryable?:bool,model?:string}
     */
    public function generateImage(string $prompt, array $options = []): array;

    /**
     * Live model catalogue from the provider's API.
     *
     * @return array{ok:bool,models?:array<int,array{id:string,name:string,input_text:int,input_image:int,output_text:int,output_image:int,context_length:?int,prompt_price:?string,completion_price:?string}>,error?:string}
     */
    public function listModels(): array;

    /**
     * Best-effort capability guess for a model id that is not in the local
     * catalogue (manual entry). Keys match listModels() rows.
     */
    public function guessCapabilities(string $modelId): array;

    /**
     * Cheap credential check with one real request.
     *
     * @return array{ok:bool,error?:string,detail?:string}
     */
    public function testConnection(): array;
}
