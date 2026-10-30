from llm import LLMClient

DISCUSS_SYSTEM_PROMPT = (
    "Ты — дружелюбный учитель. Ответь на вопрос ученика простыми и понятными словами, "
    "опираясь на текст параграфа. Не выдумывай фактов; если в тексте нет ответа, "
    "честно скажи об этом."
)


async def answer_question(llm: LLMClient, paragraph_text: str, question: str) -> str:
    return await llm.complete(
        DISCUSS_SYSTEM_PROMPT,
        f"Текст параграфа:\n{paragraph_text}\n\nВопрос ученика: {question}",
    )
