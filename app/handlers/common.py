from maxapi.types import MessageCallback, MessageCreated


def get_user_and_chat_ids(event: MessageCreated | MessageCallback) -> tuple[int, int]:
    chat_id, user_id = event.get_ids()
    if chat_id is None or user_id is None:
        raise ValueError("В событии MAX отсутствует идентификатор чата или пользователя")
    return user_id, chat_id
