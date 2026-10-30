<h1 align="center"><img src="app/assets/img/maestro-logo.svg" alt="MAESTRO" width="320"></h1>

<p align="center">Multi-Actor Experimental Synthetic Testbed for Research in Organizations</p>

<p align="center">
  <a href="https://maestro-lab.science">Visit the website</a> ·
  <a href="https://maestro-lab.science/app/projects.php">Open the studio</a> ·
  <a href="SELF-HOSTING.md">See the self-hosting instructions</a>
</p>

**Run controlled experiments on social dynamics in realistic group conversations.**

MAESTRO lets you design **synthetic social systems**: a **human participant** interacts in real
time with several **LLM-based actors**, each with its own personality and communication style.
You draft the conversation and your experimental condition in advance, and an orchestrating
LLM, **the conductor**, keeps the interaction coherent whenever the participant writes
something. **Every participant gets a standardized yet responsive manipulation inside a
conversation that reacts to the participant.**

MAESTRO works with the models of **OpenAI**, **Anthropic** or **your university's gateway** via
API. MAESTRO is free, open source and ready to use, and needs no programming.

## How the conductor works

1. **The AI actors begin the conversation** by talking to each other at the pace you defined.
2. **The participant writes a message**, for example an idea, an objection or a question.
3. **The conductor analyzes the participant's message**, the preceding conversation, the
   reference script and your instructions, and decides how the actors respond.
4. **The conductor adapts the reference script to continue the conversation in a natural
   way.** The actors respond to what the participant has written, with the pacing, turn taking
   and personalities you defined. Participants feel heard, and the conversation feels natural
   while your manipulation stays consistent. The conductor never writes in the chat itself.

Steps 2 to 4 repeat after every message sent by the participant.

→ **The participant experiences a natural group conversation.** For you, every session follows
your manipulation and stays comparable across your sample.

## Build your study in four steps, without programming

Everything runs in the browser, for you and for your participants.

1. **Describe the study** and decide what participants see: task instructions above the chat,
   a waiting screen, a "ready?" check and a session timer.
2. **Choose a Large Language Model (LLM)** from OpenAI, Anthropic or another LLM gateway, for
   example your university's gateway, and set the thinking effort of the LLM. MAESTRO estimates
   the API costs, and API keys stay on the server.
3. **Set up the AI actors, the conductor instructions and the reference script.** Add
   **segments** where the situation should change, for example when new information arrives or
   an actor leaves. The built-in **AI assistant** can fully automate this step and write the
   actors, the conductor instructions and the reference script from a short description of your
   study.
4. **Test the setup and share the participant link** in Qualtrics or Prolific with a piped
   conversation ID. Conversations arrive on the data page, ready for export as a **CSV table**,
   **PDF transcripts** or **a ZIP including all files**.

## Example studies

The studio includes the example studies from the accompanying paper (see links in the table
below).

| Example study | Condition | Links |
|---|---|---|
| Norms | High psychological safety | [Try the chat](https://maestro-lab.science/app/aiTeam.php?project=G4MPzKHA7X8GpDvT&conversationID=TEST) · [View the settings](https://maestro-lab.science/app/settings.php?example=G4MPzKHA7X8GpDvT) |
| Norms | Low psychological safety | [Try the chat](https://maestro-lab.science/app/aiTeam.php?project=A1OT2FZcpcIVCSFd&conversationID=TEST) · [View the settings](https://maestro-lab.science/app/settings.php?example=A1OT2FZcpcIVCSFd) |
| Interaction patterns | High leadership granting | [Try the chat](https://maestro-lab.science/app/aiTeam.php?project=4FsTHn3VRtiJpAAw&conversationID=TEST) · [View the settings](https://maestro-lab.science/app/settings.php?example=4FsTHn3VRtiJpAAw) |
| Interaction patterns | Low leadership granting | [Try the chat](https://maestro-lab.science/app/aiTeam.php?project=Ew5I1ntZ0JjuoPBp&conversationID=TEST) · [View the settings](https://maestro-lab.science/app/settings.php?example=Ew5I1ntZ0JjuoPBp) |
| Role expectations | Intrasender role conflict | [Try the chat](https://maestro-lab.science/app/aiTeam.php?project=rDROWhGa5Dp4LPQN&conversationID=TEST) · [View the settings](https://maestro-lab.science/app/settings.php?example=rDROWhGa5Dp4LPQN) |
| Role expectations | Intersender role conflict | [Try the chat](https://maestro-lab.science/app/aiTeam.php?project=1yybXHqNsdWsQxR4&conversationID=TEST) · [View the settings](https://maestro-lab.science/app/settings.php?example=1yybXHqNsdWsQxR4) |

## Self-hosting

The hosted studio needs no installation. To run MAESTRO on your own server, see the
[self-hosting instructions](SELF-HOSTING.md).

## Citation

If you use MAESTRO in your research, please cite the accompanying paper. The paper is under
peer review, so the citation is anonymous for now.

> Anonymous authors (2026). MAESTRO: Orchestrating Social Dynamics with Artificial
> Intelligence. Manuscript under review.
